<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The app's realtime SDK: `Realtime::to( 'order:42' )->emit( 'updated', [ 'status' => 'paid' ] )`,
 * `Realtime::room( 'chat:{a}:{b}', fn ( $user, $p ) => …, [ 'presence' => true ] )`, `Realtime::in( 'chat:1' )->members()`.
 *
 * @method static string app()
 * @method static string site()
 * @method static \WordPlus\Realtime\Realtime room(string $pattern, ?callable $rule = null, array $options = [])
 * @method static \WordPlus\Realtime\Realtime fallback(?callable $rule, ?callable $options = null)
 * @method static string callToken(int|string $user, int|string $to, array $options = [])
 * @method static string groupCallToken(int|string $user, string $name, array $options = [])
 * @method static string streamToken(int|string|null $user, string $name, array $options = [])
 * @method static \WordPlus\Realtime\Realtime calls(string $pattern, callable $rule, array $options = [])
 * @method static \WordPlus\Realtime\Realtime groupCalls(string $pattern, callable $rule, array $options = [])
 * @method static \WordPlus\Realtime\Realtime streams(string $pattern, callable $rule, array $settings = [])
 * @method static \WordPlus\Realtime\Realtime kind(string $kind, callable $mayHave, ?callable $newest = null)
 * @method static \WordPlus\Realtime\Realtime on(string $type, callable $handler)
 * @method static \WordPlus\Realtime\Emitter to(string|array $rooms)
 * @method static \WordPlus\Realtime\Emitter toUser(int|string|array $users)
 * @method static \WordPlus\Realtime\EveryoneEmitter toEveryone()
 * @method static \WordPlus\Realtime\RoomQueries in(string $room)
 * @method static \WordPlus\Realtime\OnlineQueries online()
 * @method static array listRooms(array $page = [])
 * @method static \WordPlus\Realtime\UserQueries user(int|string $id)
 * @method static array usage()
 * @method static \WordPlus\Realtime\WebhookQueries webhooks()
 * @method static \WordPlus\Realtime\RecordingQueries recordings()
 * @method static array listCalls()
 * @method static \WordPlus\Realtime\CallQueries call(string $name)
 * @method static array listStreams()
 * @method static \WordPlus\Realtime\StreamQueries stream(string $name)
 * @method static array batch(array $events)
 * @method static bool mayJoin(string $room, int|string|null $user)
 * @method static bool mayHave(string $label, int|string|null $user)
 * @method static array|null identity(int|string|null $user)
 * @method static void rotateRoom(string $room)
 * @method static void rotateUser(int|string $user)
 * @method static array config(int|string|null $user = null, array $headers = [])
 * @method static string head(int|string|null $user = null, array $headers = [], bool $importmap = true)
 * @method static string fileAt(string $name)
 * @method static \WordPlus\Realtime\Keys keys()
 *
 * @see \WordPlus\Realtime\Realtime
 */
final class Realtime extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \WordPlus\Realtime\Realtime::class;
    }
}
