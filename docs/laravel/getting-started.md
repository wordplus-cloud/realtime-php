# Getting started with Laravel

Live updates, chat, who is online, calls, group calls and live streams in a Laravel app, through WordPlus Cloud, with the broadcasting built into Laravel. `wordplus/realtime-php` brings:

- **A `wordplus` broadcasting driver.** `routes/channels.php` declares your rooms and who may join them, with what each keeps and allows as a third argument, and `broadcast( new OrderUpdated( $order ) )` sends to the event rooms, sealed.
- **The browser routes:** `POST /wordplus/realtime/auth`, `/calls` and `/keys`, on the `web` middleware, for the session user, and `/webhook`.
- **`@wordplusRealtime`:** the page settings, with the signed-in user keys and the CSRF token.
- **The `Realtime` facade:** calls, sending to users, asking about rooms, and keys.

The page joins rooms with `@wordplus/realtime` (`emit`, `emitWithAck`, `volatile`), not Laravel Echo, and you need neither Reverb nor Pusher. What goes through WordPlus Cloud is sealed with keys only your app and the browsers of your users hold. Ready-made parts come with it, all optional: the Better Messages chat, its list of who is online and its call screen, and the Easy Stream player. Without them, your own components draw everything on the same API ([your own interface](https://docs.wordplus.cloud/apps/interface/)). The [example app](example.md) is a whole shop built this way.

Laravel can run websockets of its own. [Why WordPlus Cloud](https://docs.wordplus.cloud/apps/why/) says what the cloud runs for you, and what it adds.

**Try it live** at [laravel.docs.wordplus.cloud](https://laravel.docs.wordplus.cloud/): the [example app](example.md) of this guide as a demo, a shop of your own for a day, with Ben and Cara opening in windows of their own.

![Two browsers side by side: Ann marks order 1042 as shipped on the staff page of the shop, and the order page of Ben shows Shipped at once, without a reload](https://docs.wordplus.cloud/img/apps/orders-live.webp)

## 1. Your app in WordPlus Cloud

**Apps are in early access.** Ask for it on our [contact form](https://www.wordplus.cloud/contact), and we turn **Apps** on in your WordPlus Cloud account. Until then your account has no Apps page.

In your WordPlus Cloud account, go to **Apps → Add app**. Name it, and list the addresses its pages are served from: `https://shop.example.com`, and `http://localhost:8000` while you develop. Only pages on those addresses may connect.

You get the app **key**, public because every page carries it, and its **secret**, for your server only. Make a **local key** of your own too: everything sealed is sealed with keys derived from it, and it never leaves your servers.

```dotenv
BROADCAST_CONNECTION=wordplus
WORDPLUS_SITE=s1500000007
WORDPLUS_SECRET=…
WORDPLUS_LOCAL_KEY=…              # openssl rand -hex 32; the same on every server of the app, kept as long as the app
```

## 2. Install

```bash
composer require wordplus/realtime-php      # PHP 8.1 or later, with sodium
npm install @wordplus/realtime              # for your Vite build, which imports it
```

Laravel finds the package by itself, and its routes serve the browser files: `GET /wordplus/realtime/files/{version}/{name}`, outside the session. The worker lets the tabs of a user share one connection, the room client runs group calls, and `realtime-ui.min.js` and `realtime-ui-streams.min.js` are the ready-made parts, the second for live streams. Nothing to copy into `public/`: each release serves the ones its package came with.

The package brings the `wordplus` broadcasting connection, so `BROADCAST_CONNECTION=wordplus` is all `.env` needs, with no `config/broadcasting.php` of your own. Laravel 11 and later also leave out `routes/channels.php`: make it, with `<?php` and `use Illuminate\Support\Facades\Broadcast;` at its top, and name it in `bootstrap/app.php`:

```php
// bootstrap/app.php
->withRouting(
    web: __DIR__ . '/../routes/web.php',
    channels: __DIR__ . '/../routes/channels.php',
    // …
)
```

Skip `php artisan install:broadcasting` here. It asks for Reverb, Pusher, Ably or Mercure, writes that one into `.env` in place of `wordplus`, and adds Echo to `resources/js`.

## 3. Your rooms

```php
// routes/channels.php: the room of an order, orders.1042, for its customer, the last 20 updates kept.
Broadcast::channel( 'orders.{order}', fn ( User $user, Order $order ) => $user->id === $order->customer_id, [ 'history' => 20 ] );
```

Each channel is a room of the same name. A `PrivateChannel( 'orders.1042' )` is the room `orders.1042`, and the third argument says what the room keeps and allows ([rooms](rooms.md)).

## 4. The page

Your page code is Laravel Echo, as Laravel has it, on WordPlus Cloud:

```bash
npm install laravel-echo
```

```blade
<head>
    @wordplusRealtime
    @vite( 'resources/js/app.js' )
</head>
```

```js
// resources/js/app.js
import Echo from 'laravel-echo';
import { WordplusConnector } from '@wordplus/realtime/echo';

window.Echo = new Echo( { broadcaster: WordplusConnector, ...window.wordplusRealtimeConfig } );

Echo.private( 'orders.1042' )
    .listen( '.updated', ( data ) => showStatus( data.status ) )
    .error( ( e ) => console.warn( e.code ) );   // forbidden, no_rule …
```

`@wordplusRealtime` writes `window.wordplusRealtimeConfig`: where to connect, the routes with the CSRF token, and the signed-in user keys, so the page connects with no request first.

- **Echo as you know it:** `Echo.private()`, `Echo.channel()` and `Echo.join()` with `here()`, `joining()` and `leaving()`, `listen( 'OrderUpdated' )` in the `App.Events` namespace or `listen( '.updated' )` as named, `notification()`, `whisper()` and `listenForWhisper()`. A whisper's name is in the channel's `emit` option ([rooms](rooms.md)).
- **Livewire** hears them the same way: `#[On( 'echo-private:orders.{order.id},.updated' )]`.
- **React and Vue:** `configureEcho( { broadcaster: WordplusConnector, ...window.wordplusRealtimeConfig } )` from `@laravel/echo-react` or `@laravel/echo-vue`, then `useEcho()` as Laravel's starter kits do.
- **`toOthers()`** skips the tab that asked, sent as `X-Socket-ID`. Echo's request interceptors add it to axios once it is on `window` (`window.axios = axios`, which Laravel 11 and later leave out), and to Vue's and jQuery's. With `fetch()`, add it yourself: `headers: { 'X-Socket-ID': Echo.socketId() }`.
- **Calls, live streams, the ready-made chat and who is online** come from `@wordplus/realtime` itself, on the connection Echo made: `window.Echo.connector.rt`. Or connect first, and hand Echo the connection: `const rt = await connect( window.wordplusRealtimeConfig )`, then `new Echo( { broadcaster: WordplusConnector, realtime: rt } )`.

## 5. Send

```php
class OrderUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct( public Order $order ) {}

    public function broadcastOn(): array
    {
        return [ new PrivateChannel( 'orders.' . $this->order->id ) ];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        return [ 'status' => $this->order->status ];
    }
}
```

```php
$order->update( [ 'status' => 'shipped' ] );
broadcast( new OrderUpdated( $order ) );
```

Everyone in the room gets `{ status }`, sealed with the room key on the way, and a page that reconnects later gets it from the room history.

## The config

`php artisan vendor:publish --tag=wordplus-realtime` copies it to `config/wordplus-realtime.php`.

| Key | Default | |
|---|---|---|
| `site`, `secret`, `local_key` | `WORDPLUS_SITE`, `WORDPLUS_SECRET`, `WORDPLUS_LOCAL_KEY` | |
| `routes.prefix` | `wordplus/realtime` | where the browser routes are |
| `routes.middleware` | `['web']` | how the routes know the user |
| `routes.enabled` | `true` | `false` to register `RealtimeController` yourself |
| `files` | `true` | the routes serve the browser files. `false`, with `worker` and `rooms`, for files your app serves itself |
| `worker`, `rooms` | `null` | where your app serves the worker and the room client itself |
| `generations` | `WORDPLUS_REALTIME_GENERATIONS` | the cache store for moving keys on |
| `seal` | `true` | `false` sends everything plain |
| `broadcasting` | `wordplus` | the connection whose `routes/channels.php` declares the rooms |
| `server`, `api` | WordPlus Cloud | `WORDPLUS_REALTIME_SERVER`, `WORDPLUS_REALTIME_API` |

## Next

- [Rooms](rooms.md), [presence and who is online](presence.md), [sending from your server](sending.md) and [events from the page](events.md), with the ready-made chat
- [Calls and group calls](calls.md), with the call screen
- [Encryption and your users](encryption.md)
- [Customizing the screens](customizing.md): their look, a piece of your own, or their whole code
- [Deploying](deploying.md), and [the example app](example.md)
- The [PHP reference](../reference.md), the [browser reference](https://docs.wordplus.cloud/apps/browser/), [errors](https://docs.wordplus.cloud/apps/errors/) and [limits](https://docs.wordplus.cloud/apps/limits/)
