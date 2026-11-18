# Sending from your PHP server

Your server sends events to rooms and to users, after its own work is saved. Everyone in the room gets them, sealed on the way, and a room that keeps history keeps them.

## To a room

```php
$realtime->to( 'order:1042' )->emit( 'updated', [ 'status' => 'shipped' ] );
$realtime->to( [ 'order:1042', 'staff' ] )->emit( 'updated', [ 'status' => 'shipped' ] );
```

- **The answer** is the kept event ids, by room, for the rooms that keep history.
- **A refusal** throws `RealtimeError`, whose `errorCode` says why: `too_large`, `too_many`, `reserved` (one of the [reserved names](https://docs.wordplus.cloud/apps/errors/#reserved-names)), `bad_room` ([errors](https://docs.wordplus.cloud/apps/errors/)).
- **Up to 1 MB** an event, to up to 1,000 rooms at once.
- **Binary:** `new WordPlus\Realtime\Binary( $bytes )` anywhere in the data, sealed with the rest. A page gets it as a `Uint8Array`. A string of bytes that aren't UTF-8 can't go as it is: encoding it throws.

## Not back to the tab that made it

A page that made the change already shows it. Send its tab with the request, and leave it out:

```js
// The page
await fetch( '/orders/1042/status', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Realtime-Tab': rt.tab },
    body: JSON.stringify( { status: 'shipped' } ),
} );
```

```php
// The server
set_status( $id, $status );
$realtime->to( "order:$id" )->except( $_SERVER['HTTP_X_REALTIME_TAB'] ?? null )->emit( 'updated', [ 'status' => $status ] );
```

## To a user

Every tab and device a user has open, wherever they are in your app:

```php
$realtime->toUser( 42 )->emit( 'notice', [ 'text' => 'Your order shipped' ] );
$realtime->toUser( [ 42, 43 ] )->to( 'staff' )->emit( 'notice', [ 'text' => 'A new order' ] );
```

```js
rt.on( 'notice', ( data ) => toast( data.text ) );   // on the page, for its signed-in user
```

It is sealed with the key of the user, so only their pages read it. A user with nothing open gets nothing: send an email for what must reach them.

## Many at once

```php
$realtime->batch( [
    [ 'rooms' => 'order:1042', 'event' => 'updated', 'data' => [ 'status' => 'shipped' ] ],
    [ 'users' => 42, 'event' => 'notice', 'data' => [ 'text' => 'Your order shipped' ] ],
] );
```

Up to 100 events a call.

## Asking about a room

```php
$realtime->in( 'shop' )->count();
$realtime->in( 'shop' )->members();                       // of a presence room, their info opened
$realtime->in( 'chat:34:42' )->history( [ 'limit' => 50 ] );
$realtime->in( 'chat:34:42' )->forget( $id );             // one kept event, forget() for all
$realtime->in( 'chat:34:42' )->kick( 43 );                // out now, and back only if your rule lets them in again
```

`kick()` also moves a sealed room key on, so what is sent after it is not theirs to read, when your server keeps the key generations ([encryption](encryption.md#when-someone-loses-access)).

## A user

```php
$realtime->user( 42 )->disconnect();                                         // out of everything at once
$replies = $realtime->user( 42 )->ask( 'cart', [ 'why' => 'checkout' ] );     // their pages reply with rt.answer( 'cart', fn )
```

`disconnect()` takes the user out of every room, call and live stream on every connection, when you sign them out or block them. Their pages ask your routes again, so your rules decide whether they come back. The rooms in use, what your app uses, the log of your webhook, and group calls and live streams from your server: [sending in depth](https://docs.wordplus.cloud/apps/sending/#a-user), [calls](https://docs.wordplus.cloud/apps/calls/#from-your-server), [live streams](https://docs.wordplus.cloud/apps/streams/#from-your-server).

## Webhooks

Your server can hear what happens in rooms. Set the address on your app page in your WordPlus Cloud account (on your computer, through a tunnel: [while you develop](https://docs.wordplus.cloud/apps/sending/#while-you-develop)), `https://shop.example.com/api/realtime/webhook`, which `respond( 'webhook', … )` answers. The rule of a room names the events of its pages that come here too (`webhook`), and `$realtime->on()` gives each event, by its name, a handler of yours. Here:

1. **The chat of two**, such as `chat:34:42`, sends its messages here too.
2. **Each message** is kept once, by its `event_id`, from signed-in people alone.
3. **A user going offline** is marked away.

```php
// 1. The chat of two sends its messages here too. $isOneOf is its rule, as in rooms.
$realtime->room( 'chat:{a}:{b}', $isOneOf, [ 'history' => 50, 'emit' => [ 'message', 'typing' ], 'webhook' => [ 'message' ] ] );

// 2. Each message, kept once by its event_id: a repeat has the same one.
$realtime->on( 'message', function ( array $e ) {
    // A guest in a public room sends with no from: kept from signed-in people alone.
    if ( ! isset( $e['from'] ) ) {
        return;
    }
    save_message( $e['event_id'], $e['room'], $e['from'], $e['data']['text'], $e['at'] );
} );

// 3. A user going offline.
$realtime->on( 'user.offline', fn ( array $e ) => mark_away( $e['user'] ) );
```

- **What comes:** the page events a rule names in `webhook`, by their name. `room.occupied` and `room.vacated`. `member.joined` and `member.left`. `user.online` and `user.offline`. `stream.live` and `stream.ended`, a live stream going live and ending, with its name in `stream`. `call.ended`, `group.joined`, `group.left`, `stream.joined` and `stream.left`, what people used, with how long once it is over, to charge them by ([charging for calls and streams](https://docs.wordplus.cloud/use-cases/charging/)). `'*'` gets them all.
- **Checked and opened:** each request is signed, and checked before your handler sees it. Sealed data comes opened.
- **At least once:** each event has its id, so a repeat can be dropped. A request that fails is tried again for 24 hours.

## Use cases

Each with the rules of your server and the code of the page:

- [Live updates and notifications](https://docs.wordplus.cloud/use-cases/live-updates/): an order moving on, and a notice to one user in every tab
- [Every page and app of a user](https://docs.wordplus.cloud/use-cases/devices/): `ask()` and `rt.answer()`, and taking a user out of everything at once
- [Charging for calls and streams](https://docs.wordplus.cloud/use-cases/charging/): what each person used, from the webhook
- [Members-only streams](https://docs.wordplus.cloud/use-cases/members-only/): telling the members by `toUser()` when a stream goes live

More, with Node and Python side by side: [sending in depth](https://docs.wordplus.cloud/apps/sending/).
