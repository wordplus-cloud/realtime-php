# wordplus/realtime-php

WordPlus Cloud's realtime SDK for PHP and Laravel, outside WordPress: your server's part of rooms, presence, who is online, calls and group calls, sealed end to end. It declares your rooms and who may join, call and see what, signs it for the browser, and sends your events to rooms and to users. The browser part is the npm package [`@wordplus/realtime`](https://docs.wordplus.cloud/apps/browser/), with the chat, the list of who is online and the call screen of Better Messages as ready-made parts.

**Docs:** a whole guide each for [Laravel](https://docs.wordplus.cloud/laravel/getting-started/) and for [PHP without a framework](https://docs.wordplus.cloud/php/getting-started/) (in this package: [docs/laravel](docs/laravel/getting-started.md), [docs/php](docs/php/getting-started.md)), and the [PHP reference](docs/reference.md).

**Examples:** the same shop in [Laravel](examples/laravel) and in [plain PHP](examples/php): sign in as Ann, Ben or Cara, and try live updates, a chat with typing, reactions and read ticks, who is online, calls and a group call.

It answers the browser exactly as WordPress's [`wordplus/realtime`](https://docs.wordplus.cloud/wordpress/getting-started/) does, and the Node package's server part: the same routes, tokens, keys and calls, checked against the same vectors (`spec/vectors.json`).

```bash
composer require wordplus/realtime-php
```

It carries the browser files too, the client as an ES module among them, and its routes serve them, so a PHP app needs no Node: `$realtime->head( $userId )` in a page head names `@wordplus/realtime` the client by an import map. With a bundler of your own, `npm install @wordplus/realtime`. Both are also on WordPlus's own mirror, for a network that can't reach Packagist or npm. For Composer, add it with `composer config repositories.wordplus composer https://packages.wordplus.cloud/composer`. For npm, install `https://packages.wordplus.cloud/npm/wordplus-realtime-1.0.0.tgz`.

Your app in your WordPlus Cloud account (Apps) gives you its key and secret. The local key is your own and never leaves your servers:

```dotenv
WORDPLUS_SITE=s1500000007
WORDPLUS_SECRET=…
WORDPLUS_LOCAL_KEY=…   # openssl rand -hex 32
```

## Laravel

Found by itself, with its `wordplus` broadcasting connection. Name it in `.env`, and write `routes/channels.php` as you always have, with what each room keeps and allows as a third argument:

```dotenv
BROADCAST_CONNECTION=wordplus
```

```php
// routes/channels.php: each channel is a room of the same name
Broadcast::channel( 'orders.{id}', fn ( $user, $id ) => $user->id === Order::find( $id )?->user_id, [ 'history' => 20 ] );
Broadcast::channel( 'room.{id}', fn ( $user ) => [ 'name' => $user->name ], [ 'emit' => [ 'typing' ] ] );   // presence: what the others see
```

```php
broadcast( new OrderPaid( $order ) )->toOthers();   // PrivateChannel( 'orders.42' ) is the room orders.42
Realtime::toUser( $order->user_id )->emit( 'notice', [ 'text' => 'Paid' ] );
```

The page gets its settings from `@wordplusRealtime` in a Blade layout, and listens with Laravel Echo, as Livewire and `useEcho()` do too:

```js
import Echo from 'laravel-echo';
import { WordplusConnector } from '@wordplus/realtime/echo';

window.Echo = new Echo( { broadcaster: WordplusConnector, ...window.wordplusRealtimeConfig } );
Echo.private( 'orders.42' ).listen( 'OrderPaid', ( data ) => refresh( data ) );
```

Calls, and rooms of your own beside routes/channels.php, in a service provider's `boot()`, added when the SDK is first used (artisan then runs without the credentials):

```php
use WordPlus\Realtime\Realtime;

$this->callAfterResolving( Realtime::class, function ( Realtime $realtime ) {
    $realtime->calls( 'support:{a}:{b}', fn ( $user, $with, array $p ) => User::find( $user )?->can( 'call', $with ) );
    $realtime->room( 'staff', fn ( $user ) => (bool) User::find( $user )?->is_staff, [ 'presence' => true ] );
} );
```

The routes are `POST /wordplus/realtime/auth`, `/calls` and `/keys` on the `web` middleware, which give the session's user, and `/webhook`, which the realtime server signs. A user's name (and `avatar`, or Jetstream's `profile_photo_url`) is what the others see. `RealtimeServiceProvider::profileUsing( fn ( $id ) => [ 'name' => …, 'avatar' => … ] )` says otherwise, and `hiddenUsing()` who is never shown online. `php artisan vendor:publish --tag=wordplus-realtime` copies the config.

## Plain PHP

```php
use WordPlus\Realtime\Realtime;

$realtime = Realtime::fromEnvironment( [ 'profile' => fn ( $user ) => [ 'name' => name_of( $user ) ] ] );
$realtime->room( 'order:{id}', fn ( $user, array $p ) => owns_order( $user, $p['id'] ), [ 'history' => 20 ] );

// GET /api/realtime/files/…, the browser files, then POST /api/realtime/auth, /calls, /keys and /webhook
if ( ! $realtime->respondFile() ) {
    $realtime->respond( basename( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ), current_user_id() );
}

// In a page head: the settings, and the import map of the client for <script type="module">
echo $realtime->head( current_user_id() );

$realtime->to( 'order:42' )->emit( 'updated', [ 'status' => 'paid' ] );
```

## In this version

- **User ids** are yours, a number or text such as `user_2abc` or `auth0|5f7c`, up to 64 characters with no space, in rooms, presence, calls and the directory alike.
- **PHP 8.1 or later**, with sodium (in PHP since 7.2) and curl or PHP's streams.

## Licence

MIT.

## Developing

```bash
composer install
vendor/bin/phpunit       # the vectors, the routes, tokens, keys, sealing and sending, and Laravel's side
```
