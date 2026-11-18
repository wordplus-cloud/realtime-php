<?php

namespace App\Providers;

use App\Models\Message;
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

        // What the app does with a webhook, added when the SDK is first used: artisan commands (composer install
        // package:discover among them) then run without the app credentials. Calls, the daily meeting and the live show
        // start from tokens of the app's own routes (TokenController), each its own check, then a token.
        $this->callAfterResolving(Realtime::class, function (Realtime $realtime) {
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
