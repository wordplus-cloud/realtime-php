# PHP reference: `wordplus/realtime-php`

The package needs PHP 8.1 or later, with sodium, and curl or the PHP streams. It takes the same options as the Node and Python packages, and answers the browser the same way. In Laravel, the `Realtime` facade and `app( Realtime::class )` are one instance, made from `config/wordplus-realtime.php` ([Laravel](laravel/getting-started.md#the-config)). The guides: [Laravel](laravel/getting-started.md), and [PHP without a framework](php/getting-started.md).

## `new Realtime( $options )`

`Realtime::fromEnvironment( $options )` is the same, with `app`, `secret` and `local_key` taken from `WORDPLUS_SITE`, `WORDPLUS_SECRET` and `WORDPLUS_LOCAL_KEY`.

| Option | |
|---|---|
| `app`, `secret` | your app key and secret. `site` is the same as `app` in its old word, and `$realtime->app()` gives it back |
| `local_key` | 64 hex characters of your own ([encryption](https://docs.wordplus.cloud/apps/encryption/#your-local-key)) |
| `profile` | `fn ( $user ) => [ 'name' => …, 'avatar' => …, 'url' => … ]` or `null`: the user directory entry, and by default their presence info and call info |
| `hidden` | `fn ( $user ) => true` for a user never shown online |
| `has_keys` | `fn ( $user ) => false` cuts a user off every key |
| `generations` | a `GenerationStore`, for `remove()`, `rotateRoom()` and `rotateUser()` |
| `seal` | `false` sends everything plain |
| `base` | where your routes are, `/api/realtime` by default, for `config()` |
| `routes` | a route at an address of its own, in place of `{base}/{name}`: `[ 'calls' => '/check-call' ]`. In Laravel, `routes.auth`, `.calls`, `.keys` and `.webhook` in `config/wordplus-realtime.php` |
| `headers` | headers the browser sends to your routes, such as a CSRF token, for `config()` |
| `files` | `false` when your app serves the browser files itself: `file()` then answers nothing, and `config()` names only the `worker` and `rooms` you give |
| `worker`, `rooms` | where your app serves the worker and the room client itself, for `config()`: from the routes by default |
| `rooms_worker` | where it serves the room client encryption worker, beside `rooms` by default |
| `token_ttl`, `call_token_ttl` | seconds: 6 hours by default. A room token lasts at most a day, a call token at most 6 hours |
| `server`, `api` | WordPlus Cloud by default |
| `http` | `fn ( $method, $url, $headers, $body ) => [ 'status' => …, 'body' => … ]`, your own client for sending |

A user is the id your app gives, an integer or a string, or `null` for nobody. Calls take any of them: an id of digits as a number, and any other id of up to 64 characters with no space as it is.

## Tokens

Who may call, join or watch is your app's own logic, in a route of your own. These sign what your route decided and check nothing else, so check first ([who may call](https://docs.wordplus.cloud/apps/calls/#who-may-call)). Each answers a string for the page. In Laravel, `Realtime::callToken()` and the others.

| Method | |
|---|---|
| `callToken( $user, $to, [ 'type', 'name', 'info', 'into' ] )` | for `rt.startCall()`: `$user` calls `$to`, two different users. `type` is `audio`, or `video` for anything else. `name` is a name of your own for the call, which `call.ended` and the other webhooks give back, `call:` and a random id by default. `info` is what the person called sees of the caller, your `profile` by default. It rings once, within `call_token_ttl`. The person called needs no token. With `into`, the `call.ref` the page sent, it is for `call.add()` and rings `$to` into that call |
| `groupCallToken( $user, $name, [ 'type', 'publish', 'subscribe', 'admin', 'hidden', 'encrypt', 'info' ] )` | for `rt.joinGroupCall()`: `$user` joins the group call `$name`, which starts as the first person joins. `publish` and `subscribe` are yes by default. `admin` hosts it. `hidden` is listed to nobody. `encrypt` encrypts its media end to end, the same in every token for the call. `info` is what the others see, your `profile` by default ([group calls](https://docs.wordplus.cloud/apps/calls/#group-calls)) |
| `streamToken( $user, $name, [ 'role', 'info', 'chat', 'mod', 'src', 'quality', 'settings' ] )` | for `rt.joinStream()`: `$user`, or `null` for a visitor, who can only be a viewer, joins the live stream `$name`. `role` is `viewer` by default, or `host`. `chat` is `write` or `read`. `mod` keeps the chat. `src` is what a host or guest may send, and `quality` the best picture they send. `settings` is `[ 'stage', 'reactions', 'people', 'chat' => [ 'offline', 'links', 'fresh' ] ]`, the same in every token for it. It lasts ten minutes ([live streams](https://docs.wordplus.cloud/apps/streams/#who-is-who)) |

They throw `\InvalidArgumentException` for what they cannot sign: a user id calls do not take, calling oneself, a name a call or a stream cannot have, an `into` of a call this user is not in, or a visitor given more than a viewer's place.

## Rules

| Method | |
|---|---|
| `room( $pattern, fn ( $user, array $p, $room ) => $answer, $options = [] )` | rooms by pattern (`order:{id}`): who may join, answered `false`, `true`, or `[ 'info' => …, 'emit' => …, 'readOnly' => … ]`. `$options`: `presence`, `count`, `history` (a number, or `[ 'last', 'seconds' ]`), `emit` and `webhook` (names, or `'*'`), `public`, `seal`. A public room may have no rule ([rooms](https://docs.wordplus.cloud/apps/rooms/)). `chat:{pair}` is a room of the two people its name holds, `chat:34:42`, and nobody else: it needs no rule, and a rule it has is asked only about those two, with `other` in `$p`. A public room takes no `{pair}` |
| `kind( $kind, fn ( $rest, $user, $label ) => bool, $newest = null )` | keys of your own kind ([encryption](https://docs.wordplus.cloud/apps/encryption/#your-own-sealed-values)) |
| `fallback( fn ( $user, $room ) => $answer, fn ( $room ) => $options )` | the rooms no pattern owns, and what they are. In Laravel, `routes/channels.php` |
| `on( $type, fn ( array $event ) => … )` | the events of a [webhook](https://docs.wordplus.cloud/apps/sending/#webhooks): the name of a browser event, `room.occupied`, `room.vacated`, `member.joined`, `member.left`, `user.online`, `user.offline`, `stream.live`, `stream.ended`, `call.ended`, `group.joined`, `group.left`, `stream.joined`, `stream.left`, `webhook.test`, or `'*'` |

`{name}` matches one or more characters other than `:` and `.`. The pattern with the most characters of its own decides, and on a tie the one declared first. Each method returns the instance, so calls chain.

**Apps with call rules:** `calls( $pattern, $rule, [ 'people' ] )`, `groupCalls( $pattern, $rule, [ 'encrypt' ] )` and `streams( $pattern, $rule, $settings )` keep working, for pages on `rt.call()`, `rt.groupCall()` and `rt.stream()`. A `calls()` rule that owns the name of a call is still asked as someone answers it, and a `groupCalls()` rule still decides a host's acts. `people`, `fn ( $user, $call ) => [ user ids ]`, is whom the call screen's Add Person lists, each still asked of the rule. On the package's calls route, a call, a group call or a stream no rule owns is refused `forbidden`.

## Routes

| Method | |
|---|---|
| `respond( $action, $user )` | answers the current request in plain PHP: `auth`, `calls`, `keys` or `webhook`, with its status, headers and JSON |
| `respondFile()` | answers the current request when it asks for a browser file (GET `{base}/files/{version}/{name}`), and says whether it did |
| `file( $method, $path, $ifNoneMatch = null )` | → `[ 'status', 'headers', 'body' ]` for a browser file, or `null` for any other request, for a framework of your own |
| `fileAt( $name )` | where the routes serve a browser file, in the folder of this version: `realtime.js`, `realtime-worker.js`, `realtime-rooms.min.js` … |
| `address( $name )`, `routeOf( $path )` | where a route is, and which route a path asks for (`null` for none): a front controller answers `respond( $realtime->routeOf( $path ), $user )` |
| `answerRequest( $method, $contentType, $action, $body, $user )` | → `[ 'status', 'body' ]`, for a framework of your own |
| `webhook( $raw, $headers, $path )` | → `[ 'status', 'body' ]`: the webhook request checked, and its events handed to the handlers of `on()` |
| `handle( $action, $body, $user )` | → `[ 'status', 'body' ]`, for a body already decoded |

The routes take JSON POSTs only: another method gets `405`, and a body that is not JSON `415`. A page on another site cannot send JSON without asking first, and it is never allowed to. A refusal is part of the answer (`denied`), not an HTTP error.

| Method | What it answers |
|---|---|
| `authorize( $rooms, $user )` | `[ 'token', 'exp', 'keys', 'denied' ]` |
| `authorizeCall( $request, $user )` | what the package's calls route answers: the page's line, the answer to a call, a renewal, each on the proofs only your server makes. `[ 'token', 'exp', 'key', 'media_key', 'pair', 'member', 'ring'? ]` for a call, `[ 'token', 'exp', 'media_key'? ]` for a group call by name, `[ 'token', 'exp' ]` for a live stream by name, `[ 'people' ]`, `[ 'may' ]` (whom the user may call, as `<online-list call>` asks), `[ 'done' ]` (an act of a host), or `[ 'denied' => … ]` |
| `keysAnswer( $labels, $user )` | `[ 'keys', 'identity'? ]` |
| `identity( $user )` | `[ 'token', 'exp' ]` or `null` |
| `publicGrants()` | the grants of the rooms declared `public`, which `config()` puts in every page |
| `config( $user = null, $headers = [] )` | the browser settings for `connect()`, with a signed-in user keys and identity |
| `head( $user = null, $headers = [], $importmap = true )` | the settings as a page `<head>` takes them, `window.wordplusRealtimeConfig`, with an import map naming `@wordplus/realtime` the client the routes serve |
| `mayJoin( $room, $user )`, `mayHave( $label, $user )` | what the routes decide, for your own checks |

## Sending

| Method | |
|---|---|
| `to( $rooms )->except( $tab )->emit( $event, $data = null, [ 'seal' => false ] )` | to one room or up to 1,000. Data up to 1 MB. The kept event ids, by room ([sending](https://docs.wordplus.cloud/apps/sending/)) |
| `toUser( $users )->emit( $event, $data )` | to every tab and device of one user or up to 1,000, sealed with the key of each. Chains with `to()` |
| `toEveryone()->except( $tab )->emit( $event, $data )` | to every page and app connected now, signed in or not, never sealed or kept |
| `batch( [ [ 'rooms', 'users', 'everyone', 'event', 'data', 'except', 'seal' ], … ] )` | several events, each sent as its own emit, one after another: any number. The kept ids, by room, for each |
| `room( $name )` | one room, by its name alone: `->emit( $event, $data, $options )`, `->count()`, `->members()` → `[ 'count', 'list' ]` (the first 1,000 members of a presence room, their info opened), `->history( [ 'before', 'after', 'limit', 'days' ] )`, `->forget( $id = null )`, `->remove( $user )`, `->removeEveryone()`, `->recheck( $user )`, `->recheckEveryone()`. `in( $room )` and `->kick( $user )` are their old names, and still work |
| `online()` | `->has( $user )`, `->states( $users )`, `->count()`, `->list( $cursor, $limit )` |
| `listRooms( [ 'prefix', 'cursor', 'limit' ] )` | `[ 'count', 'rooms' => [ [ 'name', 'count', 'connections' ] ], 'next'? ]`: the rooms someone is in now ([sending](https://docs.wordplus.cloud/apps/sending/#the-rooms-in-use)) |
| `user( $id )` | one user: `->emit( $event, $data, [ 'seal', 'except' ] )`, as `toUser( $id )` sends it, `->get()`, `->disconnect()`, `->ask( $event, $data, [ 'timeout', 'all', 'seal' ] )`: their state, out of everything at once, and their pages asked, `timeout` 1,000 to 60,000 ms, 10,000 by default ([a user](https://docs.wordplus.cloud/apps/sending/#a-user)) |
| `usage()` | `[ 'connections' => [ 'now', 'limit' ], 'credits' ]` ([what your app uses](https://docs.wordplus.cloud/apps/sending/#what-your-app-uses)) |
| `webhooks()` | `->deliveries()`, the last 100 tries. `->resend( $id )` → `[ 'delivered', 'status', 'error'? ]`. `->test()` → the same: one `webhook.test` event sent now, which `on( 'webhook.test', … )` hears ([deliveries](https://docs.wordplus.cloud/apps/sending/#deliveries-a-resend-and-a-test)) |
| `listCalls()`, `call( $name )` | the group calls going on, and `->get()`, `->mute( $user, $track? )` (their microphone, or `camera` or `screen`, → whether it was on), `->letSpeak( $user )` and `->makeListener( $user )` (→ whether they were in it), `->remove( $user )` (→ whether they were in it), `->spotlightOn( $user )` and `->spotlightOff( $user? )` (on the main tiles for everyone, up to four, off for them, or for everyone with no user, → whether it changed anything), `->end()`, the words a host has on the page. `->permit( $user, [ 'publish' => true, 'subscribe' => true ] )` changes either, `subscribe` stopping or starting what they get of the others, and `->spotlight( $user, $on )` is the last two in one ([calls](https://docs.wordplus.cloud/apps/calls/#from-your-server)). What the call holds until it ends, as a host does on the page: `->mute( $user, 'microphone', [ 'hold' => true ] )` and `->unmute( $user )` (Mute and Allow Microphone), `->permit( $user, [ 'publish' => …, 'hold' => true ] )` (Make Listener and its promotion), `->ban( $user, [ 'minutes' => … ] )` (→ `[ 'removed', 'until' ]`, until the call ends with no minutes, at most a week), `->unban( $user )` and `->bans()`, `->rules( [ 'mode', 'locked', 'muted' ] )` (→ the rules now, until everyone has left), `->hand( $user, $up )` and `->lowerHands()`, and `->ask( $user, $devices, $by )`, which keeps nothing. Each takes `'by'`, the host acted for, named in the `call.moderated` webhook ([moderating a group call](https://docs.wordplus.cloud/apps/calls/#moderating-a-group-call)) |
| `listStreams()`, `stream( $name )` | the streams live now, and `->get()`, `->people()`, `->chat( $limit )`, `->say( $text, $name, $avatar )`, `->deleteMessage( $id )`, `->clearChat()`, `->pin( $id )`, `->slow( $seconds )`, `->timeout( $user, $seconds )`, `->untimeout( $user )`, `->remove( $user )` (`->kick( $user )` is the same), `->ban( $user )`, `->unban( $user )`, `->bans()`, `->reactions( $on )`, `->end()` ([live streams](https://docs.wordplus.cloud/apps/streams/#from-your-server)) |
| `rotateRoom( $room )`, `rotateUser( $user )` | moves a key on. Needs `generations` |

They throw `RealtimeError` when WordPlus Cloud refuses: `$e->errorCode` holds the [code](https://docs.wordplus.cloud/apps/errors/), and `$e->status` the HTTP status.

## Keys

`$realtime->keys()` is the app `Keys`:

| Method | |
|---|---|
| `seal( $text, $label )`, `sealJson( $data, $label )` | a sealed value, `{label}:1.{…}` |
| `open( $value )` | the text, or `null` |
| `entry( $label )` | a key as the browser takes it |
| `dayLabel()`, `roomLabel( $room )`, `userLabel( $user )` | the labels the SDK uses |

## Generations

```php
interface GenerationStore
{
    public function get( string $name ): ?int;
    public function set( string $name, int $generation ): void;
}
```

Keep them where all your servers see them, and for good: a database row, or a cache that does not evict. In Laravel, `WordPlus\Realtime\Laravel\CacheGenerations` keeps them in a cache store.

## Helpers

`Patterns::validRoom( $name )` says whether WordPlus Cloud takes the name of a room, and `Patterns::validPattern( $pattern )` a pattern.
