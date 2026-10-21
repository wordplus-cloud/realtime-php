# Events from the page, in Laravel

Pages send events to the rooms they are in, straight through WordPlus Cloud, with no request to your app: chat messages, typing, read ticks, a cursor. The options of a room say which events its pages may send.

![The chat window of Ben shows “Ann typing” while Ann, in her own browser, writes a reply](https://docs.wordplus.cloud/img/apps/chat-typing.webp)

## What a room allows

```php
// routes/channels.php: the chat of Ann and Ben. They send messages, typing, reactions and read ticks. The messages and
// reactions reach this app by webhook, which keeps the conversation.
Broadcast::channel( 'chat.{a}.{b}', fn ( User $user, string $a, string $b ) => in_array( (string) $user->id, [ $a, $b ], true ),
    [ 'history' => 50, 'emit' => [ 'message', 'typing', 'reaction', 'read' ], 'webhook' => [ 'message', 'reaction' ] ] );
```

- **`emit`** lists the names, or `'*'` for any. None by default: a room your app alone sends to.
- **`webhook`** names those your app gets too, to keep them ([below](#keeping-the-conversation)).
- **`history`** keeps the last events of the room for 5 minutes: what a page that reconnects missed comes again from it.
- **The answer for one user** can narrow it, from a room of the facade: `[ 'emit' => [ 'typing' ] ]`, or `[ 'readOnly' => true ]`.
- **Reserved:** `joined`, `join`, `leave`, `count` and `error` are the names kept for the room itself.

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

The conversation is for your app to keep, in its own database, as Better Messages keeps its own in the WordPress database. A room keeps its events for 5 minutes, for pages that reconnect. Your app gets the events the room names in `webhook` ([webhooks](sending.md#webhooks)) and saves them by `event_id`, the id the pages saw as `meta.id`. A route gives them back, a page at a time:

```php
// the boot() of a service provider
use App\Models\Message;
use Illuminate\Support\Carbon;
use WordPlus\Realtime\Realtime;

$this->callAfterResolving( Realtime::class, function ( Realtime $realtime ) {
    // A guest in a public room sends with no `from`: kept here from signed-in people alone. A repeat has the same id.
    $realtime->on( 'message', function ( array $e ) {
        if ( isset( $e['from'] ) ) {
            Message::firstOrCreate( [ 'event_id' => $e['event_id'] ], [
                'room' => $e['room'], 'from_id' => $e['from'], 'text' => $e['data']['text'], 'created_at' => Carbon::createFromTimestampMs( $e['at'] ),
            ] );
        }
    } );

    // One reaction a person on a message, an empty key taking it back.
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

// routes/web.php: the newest 50, or with ?before=<id> the 50 before that one, oldest first. Only its two people may
// read it.
Route::get( '/chat/{a}/{b}/messages', function ( Request $request, string $a, string $b ) {
    abort_unless( in_array( (string) $request->user()->id, [ $a, $b ], true ), 403 );
    $before = Message::firstWhere( 'event_id', $request->query( 'before' ) );
    $page = Message::where( 'room', "chat.{$a}.{$b}" )->when( $before, fn ( $q ) => $q->where( 'id', '<', $before->id ) )
        ->orderByDesc( 'id' )->limit( 51 )->get();

    return [ 'messages' => $page->take( 50 )->reverse()->values()->map->toChat(), 'more' => $page->count() > 50 ];   // [ id, from, text, at, reactions ]
} )->middleware( 'auth' );
```

- **Check there anything that matters:** the events do not go through your app before they are sent on.
- **On localhost** no webhook reaches your app, as a webhook needs an address on the internet. While you build there, the ready-made chat tells your app of each message it sent with its `sent` event, for a route of yours to keep by the same id ([without a webhook](https://docs.wordplus.cloud/use-cases/chat/#without-a-webhook-as-on-localhost)). Or a tunnel gives your computer an address ([while you develop](https://docs.wordplus.cloud/apps/sending/#while-you-develop)).
- **`at`** is in milliseconds, and `reactions` maps each reaction to who chose it. A reaction comes as `{ id, key }`: the one reaction of the user to message `id`, an empty `key` taking it back.
- **A gap:** a page back after the 5 minutes a room keeps its events, or after more came than it keeps, gets what the room still had, then `error` on the room with `{ code: 'gap' }`, still joined. Some events are lost to it, so ask your route for the newest page again: `chat.on( 'error', ( e ) => e.code === 'gap' && reload() )`.

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

More, with Node and Python side by side: [events in depth](https://docs.wordplus.cloud/apps/events/).
