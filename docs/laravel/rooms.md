# Rooms in Laravel

A room is a name pages join to get its events. The Laravel broadcasting names them: each channel is a room of the same name, so `new PrivateChannel( 'orders.1042' )` and `new PresenceChannel( 'shop' )` are the rooms `orders.1042` and `shop`. `routes/channels.php` declares them, once, with what each keeps and allows.

## Declaring rooms: `routes/channels.php`

Written as for any broadcaster, with the room options as a third argument:

```php
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// The updates of an order: its customer, and the staff, the last 20 kept. {order} is bound to the model, as in routes.
Broadcast::channel( 'orders.{order}', fn ( User $user, Order $order ) => $user->id === $order->customer_id || $user->staff, [ 'history' => 20 ] );

// A chat between two people, chat.34.42: those two only. They send messages and typing to it, and the messages are kept.
Broadcast::channel( 'chat.{a}.{b}', fn ( User $user, string $a, string $b ) => in_array( (string) $user->id, [ $a, $b ], true ), [ 'history' => 50, 'emit' => [ 'message', 'typing' ] ] );

// The page of a product: anyone, signed in or not, with a live count of who is looking.
Broadcast::channel( 'product.{id}', fn () => true, [ 'public' => true, 'count' => true ] );
```

- **The user** is the one signed in to the session, on the `web` middleware of the browser routes.
- **The answer:** `false` for no, `true` for yes, or an array: the member info, which makes the room a [presence](presence.md) room, as the Laravel presence channels have it.
- **When it runs:** when a page joins, and when its token is renewed (every 6 hours by default). A page asks for every room it joins in the same moment in one request, and keeps the tokens across reconnects and moves.

## What a room keeps and allows

| Option | Default | |
|---|---|---|
| `history` | none | Events kept for pages that reconnect: a number (`50`), up to 1,000, for 5 minutes. Or `[ 'last' => 50, 'days' => 0.002 ]` for less (0.002 of a day is about 3 minutes) |
| `emit` | none | Events pages may send to the room: names, or `'*'` for any ([events from the page](events.md)) |
| `presence` | off | Its members are listed, with their info ([presence](presence.md)). An answer that is an array sets it too |
| `count` | off | A live count of who is in it, at any size, without a list |
| `webhook` | none | Which of the page events your app gets too ([sending](sending.md#webhooks)) |
| `public` | off | Anyone may join, signed in or not, without asking the callback |
| `seal` | on | Its events, its member info and its history are sealed with the room key ([encryption](encryption.md)) |

The options travel in the room token, signed by your app, so WordPlus Cloud enforces them and nothing is set in two places.

## Rooms of your own

Beside `routes/channels.php`, the facade declares rooms by pattern, as the other packages do, with `:` in their names if you like. In the `boot()` of a service provider:

```php
use WordPlus\Realtime\Realtime;

$this->callAfterResolving( Realtime::class, function ( Realtime $realtime ) {
    $realtime->room( 'staff', fn ( $user ) => (bool) User::find( $user )?->staff, [ 'presence' => true ] );
} );
```

- **`$user` is an id** here, not a model, and the pattern parts come as an array: `fn ( $user, array $p ) => …`.
- **Which decides:** a room a `room()` pattern owns is decided there. `routes/channels.php` decides the rest.
- **`callAfterResolving`** adds it when the SDK is first used, so artisan commands run without the app credentials.

## Joining

```js
const order = rt.join( 'orders.1042' );
order.on( 'updated', ( data, meta ) => showStatus( data.status ) );   // meta: { from, id, at }
order.on( 'joined', () => showLive() );
order.on( 'error', ( e ) => console.warn( e.code ) );   // forbidden, no_rule, full …

rt.leave( 'orders.1042' );
```

- **The event name** is what the event `broadcastAs()` says, or its class (`App\Events\OrderUpdated`).
- The tabs of a user ride one connection (the worker), each with its own rooms.
- **Events reach a listener opened:** you never handle sealed values.
- **Reserved names:** `joined`, `join`, `leave`, `count` and `error` are kept for the room itself.

## History, and nothing lost

A room keeps its events for 5 minutes at most, for recovery. Your app keeps its own records, such as the messages of a chat, from the [webhook](events.md#keeping-the-conversation).

A room that keeps history gives a page that comes back what it missed: a reconnect within the history span gets the missed events first, in order, with `meta.replayed`, then the new ones.

```js
const { events, more } = await order.history( { limit: 50 } );          // the newest 50, oldest first
const older = await order.history( { before: events[ 0 ].id, limit: 50 } );
```

Events over 64 KB are sent but not kept. `volatile` ones are never kept. Your app reads and erases history too: `Realtime::in( 'orders.1042' )->history()` and `->forget()` ([sending](sending.md#asking-about-a-room)).

More, with Node and Python side by side: [rooms in depth](https://docs.wordplus.cloud/apps/rooms/).
