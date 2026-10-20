<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Laravel;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use WordPlus\Realtime\Realtime;
use WordPlus\Realtime\RealtimeError;

/**
 * Laravel's broadcasting on WordPlus Cloud (`'driver' => 'wordplus'` in config/broadcasting.php): `broadcast( new
 * OrderPaid( $order ) )` emits to the event's rooms, sealed, and routes/channels.php decides who may join them, as
 * Laravel developers already write it. The browser joins with @wordplus/realtime, not Laravel Echo, by the channel's
 * own name: a PrivateChannel('orders.42') is the room `orders.42`, and so is a PresenceChannel's.
 *
 * - `Broadcast::channel( 'orders.{id}', $callback, [ 'history' => 20, 'emit' => [ 'typing' ] ] )`: a room's options
 *   are the channel's own third argument (beside Laravel's `guards`).
 * - A callback that answers an array makes the room a presence room, the array the member's info, as Laravel's
 *   presence channels have it.
 * - `[ 'public' => true ]` lets anyone in, signed in or not, without asking the callback: a public Channel's room.
 * - `toOthers()` skips the tab whose request made the event, when the page sends its tab (`rt.tab`) as X-Realtime-Tab
 *   (or X-Socket-ID).
 */
final class WordPlusBroadcaster extends Broadcaster
{
    /** The room options a channel's third argument may hold. */
    private const OPTIONS = ['presence', 'count', 'history', 'emit', 'webhook', 'public', 'seal'];

    private ?Realtime $resolved = null;

    /**
     * The package, or how to make it: made on first use, so routes/channels.php registers its channels, and artisan lists
     * or caches the routes, before WORDPLUS_SITE and WORDPLUS_SECRET are set, as in a Docker or CI build (an SDK review,
     * 2026-10-14: route:list and optimize threw without them).
     */
    public function __construct(private readonly Realtime|\Closure $realtime) {}

    private function realtime(): Realtime
    {
        if ($this->realtime instanceof Realtime) {
            return $this->realtime;
        }

        return $this->resolved ??= ($this->realtime)();
    }

    /**
     * Whether routes/channels.php lets the request's user into a room: null for no, true, or a presence member's info as
     * the channel's callback gave it.
     *
     * @return array{info?: mixed, presence?: bool}|null
     */
    public function allows(Request $request, string $room): ?array
    {
        // A public room lets anyone in, signed in or not, without asking its callback.
        if (!empty($this->optionsFor($room)['public'])) {
            return [];
        }

        // Nobody signed in gets a channel with a callback, as Laravel's own broadcasters decide before asking.
        if ([] === $this->channels || null === $this->retrieveUser($request, $room)) {
            return null;
        }

        try {
            $result = $this->verifyUserCanAccessChannel($request, $room);
        } catch (AccessDeniedHttpException) {
            return null;
        }

        if (!$result) {
            return null;
        }

        return is_array($result) ? ['info' => $result, 'presence' => true] : [];
    }

    /**
     * A room's options, from the channel routes/channels.php has for it: null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function optionsFor(string $room): ?array
    {
        foreach (array_keys($this->channels) as $pattern) {
            if ($this->channelNameMatchesPattern($room, (string) $pattern)) {
                return array_intersect_key((array) ($this->channelOptions[$pattern] ?? []), array_flip(self::OPTIONS));
            }
        }

        return null;
    }

    /** The auth route Laravel Echo would use; @wordplus/realtime asks the package's own routes instead. */
    public function auth($request): mixed
    {
        $room = self::normalize((string) $request->input('channel_name', ''));
        $user = $request->user()?->getAuthIdentifier();

        return $this->realtime()->authorize([$room], $user);
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return $result;
    }

    /** @param array<mixed> $channels */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $socket = Arr::pull($payload, 'socket');
        $rooms = array_values(array_unique(array_map(static fn ($channel) => self::normalize((string) $channel), $channels)));

        try {
            $this->realtime()->to($rooms)->except(is_string($socket) ? $socket : null)->emit((string) $event, $payload);
        } catch (RealtimeError $e) {
            throw new BroadcastException('WordPlus Cloud refused the broadcast: '.$e->errorCode, $e->status, $e);
        }
    }

    /** A channel's name as the room it is: without Laravel's `private-` or `presence-`. */
    private static function normalize(string $channel): string
    {
        foreach (['private-', 'presence-'] as $prefix) {
            if (str_starts_with($channel, $prefix)) {
                return substr($channel, strlen($prefix));
            }
        }

        return $channel;
    }
}
