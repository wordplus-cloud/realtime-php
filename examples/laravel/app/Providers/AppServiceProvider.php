<?php

namespace App\Providers;

use App\Models\Message;
use App\Models\User;
use App\Support\Demo;
use App\Support\Shop;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use WordPlus\Realtime\Realtime;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The site of a demo, a proxy that speaks HTTPS for it: its addresses are https ones.
        if (Demo::on()) {
            URL::forceScheme('https');
        }

        // Who may call whom, and what the app does with a webhook, added when the SDK is first used: artisan commands
        // (composer install package:discover among them) then run without the app credentials.
        $this->callAfterResolving(Realtime::class, function (Realtime $realtime) {
            $staff = fn ($id) => (bool) User::find($id)?->staff;
            $sameShop = fn (...$ids) => 1 === count(array_unique(array_map(fn ($id) => Shop::of($id), $ids)));

            // #region demo: members
            // Calls between two people, support:34:42: the two it names, one of them staff. Either of them may bring
            // more staff in, and the call goes on as a group call: the one adding calls the staff member about it, who
            // takes the call from them. $user and $with are ids.
            $realtime->calls('support:{a}:{b}', function ($user, $with, array $p) use ($staff, $sameShop) {
                $pair = [(int) $p['a'], (int) $p['b']];
                if (!$sameShop($user, $with, ...$pair)) {
                    return false;
                }

                if (in_array($user, $pair, true) && in_array($with, $pair, true)) {
                    return $staff($user) || $staff($with);
                }

                return (in_array($user, $pair, true) && $staff($with)) || ($staff($user) && in_array($with, $pair, true));
            }, [
                // Whom the call screen offers to add: the shop staff not in the call.
                'people' => fn ($user, string $call) => Shop::people(Shop::of($user))->where('staff', true)
                    ->whereNotIn('id', array_map('intval', array_slice(explode(':', $call), 1)))->pluck('id')->values()->all(),
            ]);
            // #endregion

            // #region demo: meeting
            // The daily meeting: a group call for everyone in the shop.
            $realtime->groupCalls('meeting:{id}', fn ($user, array $p) => null !== User::find($user) && Shop::of($user) === ('daily' === $p['id'] ? 0 : (int) $p['id']));
            // #endregion

            // #region demo: show
            // The shop live show, show:mugs: Ann presents it, and everyone else watches and writes in its chat, signed in or not.
            $realtime->streams('show:{id}', function ($user, array $p) {
                $s = Shop::ofRoom('show:'.$p['id']);
                if (null === $s || !Shop::exists($s)) {
                    return false;
                }

                return null !== $user && Shop::of($user) === $s && 'Ann' === User::find($user)?->name ? 'host' : 'viewer';
            }, ['reactions' => true]);
            // #endregion

            // #region demo: webhooks
            // What WordPlus Cloud tells the app by webhook (set https://<your app>/wordplus/realtime/webhook on your
            // app page in WordPlus Cloud): a message or reaction from a page, someone coming online, the live show going
            // live … each sent on to its shop webhooks room. A live stream event names its stream (show:mugs) where the
            // others name a room.
            $realtime->on('*', function (array $event) use ($realtime) {
                $room = $event['room'] ?? $event['stream'] ?? null;
                $s = null !== $room ? Shop::ofRoom($room) : Shop::of($event['user'] ?? 0);
                if (null === $s || !Shop::exists($s) || str_starts_with((string) $room, 'webhooks')) {
                    return;
                }
                // #region demo: chat
                // The chat messages and reactions: the app keeps the conversation.
                if ('emit' === $event['type'] && Shop::rooms($s)['chat'] === $room) {
                    Message::keep($event);
                }
                // #endregion
                $type = 'emit' === $event['type'] ? $event['event'] : $event['type'];
                $realtime->to(Shop::rooms($s)['webhooks'])->emit('webhook', ['type' => $type, 'room' => $room, 'user' => $event['user'] ?? $event['from'] ?? null, 'at' => $event['at'] ?? null]);
            });
            // #endregion
        });
    }
}
