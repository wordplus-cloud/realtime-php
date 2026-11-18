# Events from the page, in Laravel

Pages send events to the rooms they are in, straight through WordPlus Cloud, with no request to your app: chat messages, typing, read ticks, a cursor. The options of a room say which events its pages may send.

![The chat window of Ben shows “Ann typing” while Ann, in her own browser, writes a reply](https://docs.wordplus.cloud/img/apps/chat-typing.webp)

## What a room allows

The chat of Ann and Ben is named after them, `chat.34.42`, the smaller id first. A page that joins it asks your app first, and a rule answers: the callback of the channel whose pattern fits, `chat.{a}.{b}`. WordPlus Cloud then holds the page to the answer, and to the events the room lets it send ([permissions](https://docs.wordplus.cloud/apps/permissions/)). This rule says:

1. **The pages send** messages, typing, reactions and read ticks to it, and nothing else.
2. **Your app gets** the messages and reactions too, by webhook, to keep the conversation, and the room keeps its last 50 events for a page that reconnects.
3. **The two people** in the name join, and nobody else.

```php
// routes/channels.php
Broadcast::channel( 'chat.{a}.{b}', function ( User $user, string $a, string $b ) {
    // 3. The two people in the name, and nobody else.
    return in_array( (string) $user->id, [ $a, $b ], true );
}, [
    // 1. What the pages may send to it.
    'emit' => [ 'message', 'typing', 'reaction', 'read' ],
    // 2. What your app gets too, and the last 50 kept for a page that reconnects.
    'webhook' => [ 'message', 'reaction' ],
    'history' => 50,
] );
```

- **`emit`** lists the names, or `'*'` for any. None by default: a room your app alone sends to.
- **`webhook`** names those your app gets too, to keep them ([below](#keeping-the-conversation)).
- **`history`** keeps the last events of the room for 5 minutes: what a page that reconnects missed comes again from it.
- **The answer for one user** can narrow it, from a room of the facade: `[ 'emit' => [ 'typing' ] ]`, or `[ 'readOnly' => true ]`.
- **Reserved:** `joined`, `join`, `leave`, `count`, `error`, `kicked`, `connect`, `disconnect`, `connect_error`, `state` and `online` belong to the room and the connection, and are never allowed ([reserved names](https://docs.wordplus.cloud/apps/errors/#reserved-names)).

## Sending

A page sends to a room it has joined, and hears what the others send. With Laravel Echo, a page whispers: `Echo.private( 'chat.34.42' ).whisper( 'typing', { on: true } )`, heard with `listenForWhisper( 'typing', … )`, sent and never kept. Messages kept and acknowledged are the client's, on the connection Echo made (`window.Echo.connector.rt`):

```js
const chat = rt.join( 'chat.34.42' );

chat.emit( 'message', { text: 'Hi' } );                                // sent, nothing waited for
const { id, at } = await chat.emitWithAck( 'message', { text: 'Hi' } ); // WordPlus Cloud took it: checked, kept, sent
chat.volatile.emit( 'typing', { on: true } );                          // dropped rather than queued while off line, never kept
await chat.timeout( 5000 ).emitWithAck( 'message', { text: 'Hi' } );   // an ack waited for 5 seconds at most

chat.on( 'message', ( data, meta ) => draw( data.text, meta.from ) );   // meta: { from, id, at }
```

- **Everyone in the room** gets it, the other tabs and devices of the sender too. The tab that sent it does not, so it draws its own at once.
- **`emitWithAck`** resolves with the kept event id and time, or rejects with a code: `not_allowed` (the room does not list it), `forbidden`, `rate_limited`, `too_large`.
- **`meta.from`** is the sender user id, as your app vouched for them: a page cannot send as someone else.
- **Binary:** an `ArrayBuffer`, `Uint8Array` or `Blob` may be anywhere in the data. It is sealed as binary.
- **Limits:** 1 MB an event, 50 a second a connection, in bursts of up to 100.

## Typing, done the usual way

```js
let sent = 0;
input.addEventListener( 'input', () => {
    if ( Date.now() - sent < 2000 ) return;   // at most every 2 seconds
    sent = Date.now();
    chat.volatile.emit( 'typing', { on: true } );
} );

chat.on( 'typing', ( data, meta ) => showTyping( meta.from, data.on ) );   // and hide it after 4 seconds of nothing
```

## Keeping the conversation

The conversation is for your app to keep, in its own database, as Better Messages keeps its own in the WordPress database. A room keeps its events for 5 minutes, for pages that reconnect. Your app gets the events the room names in `webhook` ([webhooks](sending.md#webhooks)) and saves them by `event_id`, the id the pages saw as `meta.id`. A route of yours gives them back, a page at a time: `/chat/34/42/messages` for the chat `chat.34.42`. Here:

1. **Each message** is kept once, by its `event_id`, from signed-in people alone, and each reaction on it.
2. **The route** gives the newest 50, or with `?before=<id>` the 50 before that one, oldest first.
3. **Only the two people** of the chat may read it.

```php
// the boot() of a service provider
use App\Models\Message;
use Illuminate\Support\Carbon;
use WordPlus\Realtime\Realtime;

$this->callAfterResolving( Realtime::class, function ( Realtime $realtime ) {
    // 1. Each message, kept once by its event_id: a repeat has the same one.
    $realtime->on( 'message', function ( array $e ) {
        // A guest in a public room sends with no `from`: kept from signed-in people alone.
        if ( ! isset( $e['from'] ) ) {
            return;
        }
        Message::firstOrCreate( [ 'event_id' => $e['event_id'] ], [
            'room' => $e['room'], 'from_id' => $e['from'], 'text' => $e['data']['text'], 'created_at' => Carbon::createFromTimestampMs( $e['at'] ),
        ] );
    } );

    // 1. And each reaction: one a person on a message, an empty key taking it back.
    $realtime->on( 'reaction', function ( array $e ) {
        $message = isset( $e['from'] ) ? Message::firstWhere( 'event_id', $e['data']['id'] ) : null;
        $message?->react( (string) $e['from'], (string) $e['data']['key'] );
    } );
} );
```

```php
// app/Models/Message.php: a migration with event_id (unique), room, from_id, text, a json reactions and the timestamps.
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [ 'event_id', 'room', 'from_id', 'text', 'created_at' ];

    protected $casts = [ 'reactions' => 'array' ];   // user id => their reaction

    public function react( string $user, string $key ): void
    {
        $reactions = $this->reactions ?? [];
        $reactions[ $user ] = $key;
        $this->reactions = array_filter( $reactions );
        $this->save();
    }

    // What the chat draws: its id, who sent it, the text, the time in milliseconds, and each reaction with who chose it.
    public function toChat(): array
    {
        $reactions = [];
        foreach ( $this->reactions ?? [] as $user => $key ) {
            $reactions[ $key ][] = (string) $user;
        }

        return [ 'id' => $this->event_id, 'from' => $this->from_id, 'text' => $this->text, 'at' => $this->created_at->getTimestampMs(), 'reactions' => $reactions ];
    }
}

// routes/web.php
// 2. The newest 50, or with ?before=<id> the 50 before that one, oldest first.
Route::get( '/chat/{a}/{b}/messages', function ( Request $request, string $a, string $b ) {
    // 3. Only the two people of the chat.
    abort_unless( in_array( (string) $request->user()->id, [ $a, $b ], true ), 403 );

    $before = Message::firstWhere( 'event_id', $request->query( 'before' ) );
    $page = Message::where( 'room', "chat.{$a}.{$b}" )->when( $before, fn ( $q ) => $q->where( 'id', '<', $before->id ) )
        ->orderByDesc( 'id' )->limit( 51 )->get();

    return [ 'messages' => $page->take( 50 )->reverse()->values()->map->toChat(), 'more' => $page->count() > 50 ];   // [ id, from, text, at, reactions ]
} )->middleware( 'auth' );
```

- **Check there anything that matters:** the events do not go through your app before they are sent on.
- **On localhost** no webhook reaches your app, as a webhook needs an address on the internet. While you build there, the ready-made conversation window (`<chat-room>`) tells your app of each message it sent with its `sent` event, for a route of yours to keep by the same id ([without a webhook](https://docs.wordplus.cloud/use-cases/chat/#without-a-webhook-as-on-localhost)). Or a tunnel gives your computer an address ([while you develop](https://docs.wordplus.cloud/apps/sending/#while-you-develop)).

  ```js
  // `<chat-room>`'s `sent` event: { id, room, text, at }, which doesn't say who from. The page's head has
  // <meta name="csrf-token" content="{{ csrf_token() }}">.
  document.querySelector( 'chat-room' ).addEventListener( 'sent', ( e ) => fetch( `/chat/${ a }/${ b }/sent`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector( 'meta[name=csrf-token]' ).content },
      body: JSON.stringify( e.detail ),
  } ) );
  ```

  ```php
  // routes/web.php: kept by its id, as the webhook keeps it, and from the one asking. Once the webhook reaches you, it is the one to trust.
  Route::post( '/chat/{a}/{b}/sent', function ( Request $request, string $a, string $b ) {
      abort_unless( in_array( (string) $request->user()->id, [ $a, $b ], true ), 403 );

      $m = $request->validate( [ 'id' => 'required|string', 'text' => 'required|string', 'at' => 'required|integer' ] );
      Message::firstOrCreate( [ 'event_id' => $m['id'] ], [
          'room' => "chat.{$a}.{$b}", 'from_id' => $request->user()->id, 'text' => $m['text'], 'created_at' => Carbon::createFromTimestampMs( $m['at'] ),
      ] );

      return response()->noContent();
  } )->middleware( 'auth' );
  ```

- **`at`** is in milliseconds, and `reactions` maps each reaction to who chose it. A reaction comes as `{ id, key }`: the one reaction of the user to message `id`, an empty `key` taking it back.
- **A gap:** a page back after the 5 minutes a room keeps its events, or after more came than it keeps, gets what the room still had, then `error` on the room with `{ code: 'gap' }`, still joined. Some events are lost to it, so ask your route for the newest page again: `chat.on( 'error', ( e ) => e.code === 'gap' && reload() )`.
- **Unread counts and pushes** are your app's too: a last-read marker for each member, which the page tells a route of yours, as read marks go volatile and never reach the webhook, and a push to Firebase or APNs for a member `online` says is offline ([what your server keeps for a chat](https://docs.wordplus.cloud/use-cases/chat-server/)).

## Ready-made: the chat

Optional, as every ready-made part: the events above are all a chat needs, and [your own interface](https://docs.wordplus.cloud/apps/interface/#a-chat) draws one your way, or runs it in React with `useChat()`. This is the ready-made one.

The Better Messages conversation, on any room: your app conversation paged back as the reader scrolls up, the room filling in what came since, the day separators, the messages of a sender stacked, typing, reactions, the read ticks and the composer. It is one element:

```blade
<chat-room room="chat.{{ $chat->a }}.{{ $chat->b }}" messages="/chat/{{ $chat->a }}/{{ $chat->b }}/messages" placeholder="{{ __( 'Write a message' ) }}"></chat-room>
```

```js
await rt.callScreen();   // loads the elements and the call screen, rt.ui() the elements without calls
```

- **`messages`** is your route, asked with the page cookies. `chat.load = async ( before ) => ( { messages, more } )` replaces it, for a token or another shape: `before` is `null` for the newest page, or the oldest message shown (`{ id, at }`). With neither, it shows what the room still has.
- **Its room** lets it send what it uses: `'emit' => [ 'message', 'typing', 'reaction', 'read' ]`, and names `message` and `reaction` in `'webhook'` for your app to keep.
- **What it sends** is what you would: `message` as `{ text }` with an ack, `typing` as `{ on }` and `read` as `{ id }`, both volatile, and `reaction` as `{ id, key }`. Your own pages and apps on the same room read it.
- **Names and pictures** come from your [directory](encryption.md#your-users).
- **It fills** the height its place gives it. Its look is that of Better Messages, in a shadow root. Its colours are CSS custom properties your page sets.

`<typing-line room="…">` is the typing line alone, for a page that draws its own messages.

## Use cases

Each with the rules of your server and the code of the page:

- [Chat](https://docs.wordplus.cloud/use-cases/chat/): conversations of two and of a group, kept by your server
- [Editing together](https://docs.wordplus.cloud/use-cases/collaboration/): cursors as volatile events, and changes kept for a page that reconnects

More, with Node and Python side by side: [events in depth](https://docs.wordplus.cloud/apps/events/).
