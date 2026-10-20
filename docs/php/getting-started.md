# Getting started with PHP

Live updates, chat, who is online, calls, group calls and live streams in a PHP app without a framework, or with Symfony, Slim or your own, through WordPlus Cloud. Two packages:

- **Your server** (`wordplus/realtime-php`) declares your rooms once, says who may join them and call whom, and sends events to rooms and to users. In plain PHP it is one route and a few rules.
- **The page** (`@wordplus/realtime`, from npm) connects to WordPlus Cloud, joins rooms, and sends and gets events: `emit`, `emitWithAck`, `volatile`.

What goes through WordPlus Cloud is sealed with keys only your server and the browsers of your users hold. Ready-made parts come with it, all optional: the Better Messages chat, its list of who is online and its call screen, and the Easy Stream player. Without them, your own components draw everything on the same API ([your own interface](https://docs.wordplus.cloud/apps/interface/)). For Laravel, its own [guide](https://docs.wordplus.cloud/laravel/getting-started/) uses the Laravel broadcasting. The [example app](example.md) is a whole shop built this way.

PHP can run websockets with a server of its own. [Why WordPlus Cloud](https://docs.wordplus.cloud/apps/why/) says what the cloud runs for you, and what it adds.

**Try it live** at [php.docs.wordplus.cloud](https://php.docs.wordplus.cloud/): the [example app](example.md) of this guide as a demo, a shop of your own for a day, with Ben and Cara opening in windows of their own.

![Two browsers side by side: Ann marks order 1042 as shipped on the staff page of the shop, and the order page of Ben shows Shipped at once, without a reload](https://docs.wordplus.cloud/img/apps/orders-live.webp)

## 1. Your app in WordPlus Cloud

**Apps are in early access.** Ask for it on our [contact form](https://www.wordplus.cloud/contact), and we turn **Apps** on in your WordPlus Cloud account. Until then your account has no Apps page.

In your WordPlus Cloud account, go to **Apps → Add app**. Name it, and list the addresses its pages are served from: `https://shop.example.com`, and `http://localhost:8000` while you develop. Only pages on those addresses may connect.

You get the app **key**, public because every page carries it, and its **secret**, for your server only. Make a **local key** of your own too: everything sealed is sealed with keys derived from it, and it never leaves your servers.

```dotenv
WORDPLUS_SITE=s1500000007
WORDPLUS_SECRET=…
WORDPLUS_LOCAL_KEY=…        # openssl rand -hex 32; the same on every server of the app, kept as long as the app
```

## 2. Install

```bash
composer require wordplus/realtime-php      # PHP 8.1 or later, with sodium
```

That is all, with no Node: the package carries the browser files, and your routes serve them (step 3). The client as an ES module, the worker that lets the tabs of a user share one connection, the room client for group calls, and `realtime-ui.min.js` and `realtime-ui-streams.min.js`, the ready-made parts, the second for live streams. Each release serves the ones its package came with, from a folder named after their version.

## 3. Your server

```php
// src/realtime.php, required by every request that needs it
use WordPlus\Realtime\Realtime;

$realtime = Realtime::fromEnvironment( [   // WORDPLUS_SITE, WORDPLUS_SECRET, WORDPLUS_LOCAL_KEY
    // What others see of a user: in presence, calls and the directory.
    'profile' => fn ( $user ) => ( $u = find_user( $user ) ) ? [ 'name' => $u->name, 'avatar' => $u->avatar_url ] : null,
] );

// The room of an order: its customer may join, and the last 20 updates are kept.
$realtime->room( 'order:{id}', fn ( $user, array $p ) => null !== $user && order_owner( $p['id'] ) === $user, [ 'history' => 20 ] );
```

```php
// public/index.php, or your router. GET /api/realtime/files/…: the browser files, from the package.
if ( $realtime->respondFile() ) {
    exit;
}

// POST /api/realtime/auth, /calls, /keys and /webhook, for the user of the session
if ( preg_match( '#^/api/realtime/(auth|calls|keys|webhook)$#', parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), $route ) ) {
    session_start( [ 'read_and_close' => true ] );
    $realtime->respond( $route[1], $_SESSION['user_id'] ?? null );
    exit;
}
```

- **The user** is whatever id your app knows them by, an integer or a string, or `null` for nobody.
- **The pattern of a room** names its parts: `order:{id}` matches `order:1042`, and the rule gets `[ 'id' => '1042' ]`.
- **The routes** take JSON POSTs, and GETs for the browser files alone. A page on another site cannot send JSON without asking first, and it is never allowed, so they need no CSRF token.
- **In a framework,** `$realtime->answerRequest( $method, $contentType, $action, $body, $user )` gives `[ 'status', 'body' ]` to answer with, `$realtime->webhook( $body, $headers, $path )` the same for the webhook, and `$realtime->file( $method, $path, $ifNoneMatch )` `[ 'status', 'headers', 'body' ]` for a browser file.

## 4. The page

Render the user settings into the page head, so it connects with no request first. `head()` writes them as `window.wordplusRealtimeConfig`, and an import map naming `@wordplus/realtime` the client your routes serve:

```php
<head>
    <?= $realtime->head( $userId ) ?>
    <script type="module" src="/order.js"></script>
</head>
```

```js
// public/order.js, as it is: no Node and no bundler
import { connect } from '@wordplus/realtime';

const rt = await connect( window.wordplusRealtimeConfig );

const order = rt.join( 'order:1042' );
order.on( 'updated', ( data ) => showStatus( data.status ) );
order.on( 'error', ( e ) => console.warn( e.code ) );   // forbidden, no_rule …
```

- **With a bundler of your own** (esbuild, Vite, webpack), `npm install @wordplus/realtime`, import it the same way, and leave the map out: `$realtime->head( $userId, [], false )`.
- **A CSRF token** your other routes ask for goes in the second argument, as the headers the page sends with each request: `[ 'X-CSRF-Token' => $token ]`.

## 5. Send

After your own work is saved:

```php
$realtime->to( 'order:1042' )->emit( 'updated', [ 'status' => 'shipped' ] );
```

Everyone in the room gets `{ status }`, sealed with the room key on the way, and a page that reconnects later gets it from the room history.

## Next

- [Rooms](rooms.md), [presence and who is online](presence.md), [sending from your server](sending.md) and [events from the page](events.md), with the ready-made chat
- [Calls and group calls](calls.md), with the call screen
- [Encryption and your users](encryption.md)
- [Customizing the screens](customizing.md): their look, a piece of your own, or their whole code
- [Deploying](deploying.md), and [the example app](example.md)
- The [PHP reference](../reference.md), the [browser reference](https://docs.wordplus.cloud/apps/browser/), [errors](https://docs.wordplus.cloud/apps/errors/) and [limits](https://docs.wordplus.cloud/apps/limits/)
