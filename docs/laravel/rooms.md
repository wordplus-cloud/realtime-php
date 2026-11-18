# Rooms in Laravel

A room is a name pages join to get its events. The Laravel broadcasting names them: each channel is a room of the same name, so `new PrivateChannel( 'orders.1042' )` and `new PresenceChannel( 'shop' )` are the rooms `orders.1042` and `shop`. `routes/channels.php` declares them, once, with what each keeps and allows.

## Declaring rooms: `routes/channels.php`

Written as for any broadcaster, with the room options as a third argument. Each room is named after what it carries: `orders.1042` for the updates of order 1042, `chat.34.42` for the chat of users 34 and 42, the smaller id first, and `product.7` for the page of product 7. A page that joins a room asks your app first, and a rule answers: the callback of the channel whose pattern fits, such as `orders.{order}`. WordPlus Cloud then holds the page to the answer ([permissions](https://docs.wordplus.cloud/apps/permissions/)). These rules say:

1. **An order** lets in its customer and the staff, and keeps its last 20 updates for a page that reconnects.
2. **A chat of two** lets in the two people its name holds, who send messages and typing to it.
3. **A product page** lets in anyone, signed in or not, with a live count of who is looking. Its callback is never asked.

```php
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// 1. The updates of an order, the last 20 kept. {order} is bound to the model, as in routes.
Broadcast::channel( 'orders.{order}', function ( User $user, Order $order ) {
    // A visitor never gets here.
    // Its customer, and the staff.
    if ( $user->id === $order->customer_id ) {
        return true;
    }

    return (bool) $user->staff;
}, [ 'history' => 20 ] );

// 2. A chat of two, such as chat.34.42: the two in the name, and nobody else. They send messages and typing to it.
Broadcast::channel( 'chat.{a}.{b}', function ( User $user, string $a, string $b ) {
    return in_array( (string) $user->id, [ $a, $b ], true );
}, [ 'history' => 50, 'emit' => [ 'message', 'typing' ] ] );

// 3. The page of a product: anyone, signed in or not, and a live count. Its callback is never asked.
Broadcast::channel( 'product.{id}', fn () => true, [ 'public' => true, 'count' => true ] );
```

- **The user** is the one signed in to the session, on the `web` middleware of the browser routes. A visitor never reaches the callback of a channel, as with every Laravel broadcaster.
- **The answer:** `false` for no, `true` for yes, or an array: the member info, which makes the room a [presence](presence.md) room, as the Laravel presence channels have it.
- **Two people, or a group:** a chat named after its two people needs no table. A chat that may hold more takes a number, `chat.7`, and its callback asks your database whether the one asking is a member ([two people, or a group](https://docs.wordplus.cloud/apps/rooms/#two-people-or-a-group)).

## Names and patterns

- **Room names:** up to 200 characters of `A-Z a-z 0-9 _ - = @ , . ; :`.
- **`{order}`** matches one or more characters other than `.`, as Laravel matches channels, and a `{order}` bound to a model, as in routes, gives the callback the model.
- **The first channel whose pattern matches decides,** as in Laravel. A room no channel and no `room()` pattern matches is refused (`no_rule`).
- **When the rule runs:** when a page joins, and when its token is renewed (every 6 hours by default). A page asks for every room it joins in the same moment in one request, and keeps the tokens across reconnects and moves.

## What a room keeps and allows

| Option | Default | |
|---|---|---|
| `history` | none | Events kept for pages that reconnect: a number (`50`), up to 1,000, for 5 minutes. Or `[ 'last' => 50, 'seconds' => 120 ]` for less |
| `emit` | none | Events pages may send to the room: names, or `'*'` for any ([events from the page](events.md)) |
| `presence` | off | Its members are listed, with their info ([presence](presence.md)). An answer that is an array sets it too |
| `count` | off | A live count of who is in it, at any size, without a list |
| `webhook` | none | Which of the page events your app gets too ([sending](sending.md#webhooks)) |
| `public` | off | Anyone may join, signed in or not, without asking the callback |
| `seal` | on | Its events, its member info and its history are sealed with the room key ([encryption](encryption.md)) |

The options travel in the room token, signed by your app, so WordPlus Cloud enforces them and nothing is set in two places.

## Rooms of your own

Beside `routes/channels.php`, the facade declares rooms by pattern, as the other packages do, with `:` in their names if you like. Here a room named `staff`, where the staff see each other. A page that joins it asks your app, and its rule says:

1. **Visitors** who are not signed in stay out. Here a rule is asked for them too, with `$user` null.
2. **The staff** join, and nobody else.

In the `boot()` of a service provider:

```php
use WordPlus\Realtime\Realtime;

$this->callAfterResolving( Realtime::class, function ( Realtime $realtime ) {
    $realtime->room( 'staff', function ( $user ) {
        // 1. Visitors stay out.
        if ( null === $user ) {
            return false;
        }

        // 2. The staff, and nobody else.
        return (bool) User::find( $user )?->staff;
    }, [ 'presence' => true ] );
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
order.on( 'error', ( e ) => console.warn( e.code ) );   // forbidden, no_rule …

rt.leave( 'orders.1042' );
```

- **The event name** is what the event `broadcastAs()` says, or its class (`App\Events\OrderUpdated`).
- The tabs of a user ride one connection (the worker), each with its own rooms.
- **Events reach a listener opened:** you never handle sealed values.
- **Reserved names:** `joined`, `join`, `leave`, `count`, `error`, `kicked`, `connect`, `disconnect`, `connect_error`, `state` and `online` belong to the room and the connection. Your server and pages cannot send them ([reserved names](https://docs.wordplus.cloud/apps/errors/#reserved-names)).

## History, and nothing lost

A room keeps its events for 5 minutes at most, for recovery. Your app keeps its own records, such as the messages of a chat, from the [webhook](events.md#keeping-the-conversation).

A room that keeps history gives a page that comes back what it missed: a reconnect within the history span gets the missed events first, in order, with `meta.replayed`, then the new ones.

```js
const { events, more } = await order.history( { limit: 50 } );          // the newest 50, oldest first
const older = await order.history( { before: events[ 0 ].id, limit: 50 } );
```

Events over 64 KB are sent but not kept. `volatile` ones are never kept. Your app reads and erases history too: `Realtime::in( 'orders.1042' )->history()` and `->forget()` ([sending](sending.md#asking-about-a-room)).

## Use cases

Each with the rules of your server and the code of the page:

- [Live updates and notifications](https://docs.wordplus.cloud/use-cases/live-updates/): an order moving on, and a notice to one user in every tab
- [Chat](https://docs.wordplus.cloud/use-cases/chat/): conversations of two and of a group, kept by your server
- [Editing together](https://docs.wordplus.cloud/use-cases/collaboration/): who is in a document, their cursors and their changes

More, with Node and Python side by side: [rooms in depth](https://docs.wordplus.cloud/apps/rooms/).
