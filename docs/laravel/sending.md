# Sending from Laravel

Your app sends events to rooms and to users: broadcast events, or the facade straight. Everyone in the room gets them, sealed on the way, and a room that keeps history keeps them.

## Broadcast events

```php
namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class OrderUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct( public Order $order ) {}

    public function broadcastOn(): array
    {
        return [ new PrivateChannel( 'orders.' . $this->order->id ) ];   // the room orders.1042
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        return [ 'id' => $this->order->id, 'status' => $this->order->status ];
    }
}
```

```php
public function status( Request $request, Order $order )
{
    $order->update( $request->validate( [ 'status' => [ 'required', Rule::in( Order::STATUSES ) ] ] ) );

    // Everyone in the room but the tab whose request this is, which shows the change already.
    broadcast( new OrderUpdated( $order ) )->toOthers();

    return response()->json( [ 'ok' => true ] );
}
```

- **The event name** is the one `broadcastAs()` returns, or else its class.
- **Its data** is what `broadcastWith()` returns, or else its public properties: up to 1 MB, sealed with the room key.
- **Timing:** `ShouldBroadcast` goes through your queue (run a worker), `ShouldBroadcastNow` during the request.
- **Failures:** a refusal from WordPlus Cloud throws the Laravel `BroadcastException`, with the [code](https://docs.wordplus.cloud/apps/errors/) in its message. Broadcast after your change is saved: a failed broadcast is a missed live update, not a lost change.

## `toOthers()`

It skips the tab whose request caused the event, when the page sends its tab as `X-Realtime-Tab`:

```js
await fetch( `/orders/${ id }/status`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': window.wordplusRealtimeConfig.auth.headers[ 'X-CSRF-TOKEN' ],
        'X-Realtime-Tab': rt.tab,
    },
    body: JSON.stringify( { status: 'shipped' } ),
} );

// With axios, once: axios.defaults.headers.common[ 'X-Realtime-Tab' ] = rt.tab
```

## With the facade

The same chain as in every package:

```php
use WordPlus\Realtime\Laravel\Facades\Realtime;

Realtime::to( 'orders.1042' )->emit( 'updated', [ 'status' => 'shipped' ] );
Realtime::to( [ 'orders.1042', 'staff' ] )->except( $request->header( 'X-Realtime-Tab' ) )->emit( 'updated', [ 'status' => 'shipped' ] );

// Every tab and device a user has open, sealed with their own key:
Realtime::toUser( $order->customer_id )->emit( 'notice', [ 'text' => 'Your order shipped' ] );

Realtime::batch( [
    [ 'rooms' => 'orders.1042', 'event' => 'updated', 'data' => [ 'status' => 'shipped' ] ],
    [ 'users' => 42, 'event' => 'notice', 'data' => [ 'text' => 'Your order shipped' ] ],
] );   // 100 events a request
```

```js
rt.on( 'notice', ( data ) => toast( data.text ) );   // on the page, for its signed-in user
```

A refusal throws `RealtimeError`, with its `errorCode`: `too_large`, `too_many`, `reserved`, `bad_room`.

## Asking about a room

```php
Realtime::in( 'shop' )->count();
Realtime::in( 'shop' )->members();                       // of a presence room, their info opened
Realtime::in( 'chat.34.42' )->history( [ 'limit' => 50 ] );
Realtime::in( 'chat.34.42' )->forget( $id );             // one kept event, forget() for all
Realtime::in( 'chat.34.42' )->kick( 43 );                // out now, back only if your channel lets them in again, and the key moves on
```

Moving a key on needs a cache store that does not evict, for the key generations: `WORDPLUS_REALTIME_GENERATIONS=redis` ([encryption](encryption.md#when-someone-loses-access)).

## A user

```php
Realtime::user( 42 )->disconnect();                                         // out of everything at once
$replies = Realtime::user( 42 )->ask( 'cart', [ 'why' => 'checkout' ] );     // their pages reply with rt.answer( 'cart', fn )
```

`disconnect()` takes the user out of every room, call and live stream on every connection, when you sign them out or block them. Their pages ask your routes again, so your rules decide whether they come back. The rooms in use, what your app uses, the log of your webhook, and group calls and live streams from your server: [sending in depth](https://docs.wordplus.cloud/apps/sending/#a-user), [calls](https://docs.wordplus.cloud/apps/calls/#from-your-server), [live streams](https://docs.wordplus.cloud/apps/streams/#from-your-server).

## Webhooks

Your app can hear what happens in rooms. Set the address on your app page in your WordPlus Cloud account (on your computer, through a tunnel: [while you develop](https://docs.wordplus.cloud/apps/sending/#while-you-develop)), `https://shop.example.com/wordplus/realtime/webhook`, which the package answers, outside the `web` middleware:

```php
// routes/channels.php: a room says which events of the pages come here too
Broadcast::channel( 'chat.{a}.{b}', $isOneOf, [ 'history' => 50, 'emit' => [ 'message', 'typing' ], 'webhook' => [ 'message' ] ] );
```

```php
// the boot() of a service provider
$this->callAfterResolving( Realtime::class, function ( Realtime $realtime ) {
    // A guest in a public room sends with no `from`: kept from signed-in people alone. Message is the model of events.md.
    $realtime->on( 'message', fn ( array $e ) => isset( $e['from'] ) && Message::firstOrCreate( [ 'event_id' => $e['event_id'] ], [ 'room' => $e['room'], 'from_id' => $e['from'], 'text' => $e['data']['text'] ] ) );
    $realtime->on( 'user.offline', fn ( array $e ) => User::whereKey( $e['user'] )->update( [ 'away' => true ] ) );
} );
```

- **What comes:** the page events a room names in `webhook`, by their name. `room.occupied` and `room.vacated`. `member.joined` and `member.left`. `user.online` and `user.offline`. `stream.live` and `stream.ended`, a live stream going live and ending, with its name in `stream`. `call.ended`, `group.joined`, `group.left`, `stream.joined` and `stream.left`, what people used, with how long once it is over, to charge them by ([charging for calls and streams](https://docs.wordplus.cloud/use-cases/charging/)). `'*'` gets them all.
- **Checked and opened:** each request is signed, and checked before your handler sees it. Sealed data comes opened.
- **At least once:** each event has its id, so a repeat can be dropped. A request that fails is tried again for 24 hours.

More, with Node and Python side by side: [sending in depth](https://docs.wordplus.cloud/apps/sending/).
