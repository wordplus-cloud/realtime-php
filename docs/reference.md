# PHP reference: `wordplus/realtime-php`

The package needs PHP 8.1 or later, with sodium, and curl or the PHP streams. It takes the same options as the Node and Python packages, and answers the browser the same way. In Laravel, the `Realtime` facade and `app( Realtime::class )` are one instance, made from `config/wordplus-realtime.php` ([Laravel](laravel/getting-started.md#the-config)). The guides: [Laravel](laravel/getting-started.md), and [PHP without a framework](php/getting-started.md).

## `new Realtime( $options )`

`Realtime::fromEnvironment( $options )` is the same, with `site`, `secret` and `local_key` taken from `WORDPLUS_SITE`, `WORDPLUS_SECRET` and `WORDPLUS_LOCAL_KEY`.

| Option | |
|---|---|
| `site`, `secret` | your app key and secret |
| `local_key` | 64 hex characters of your own ([encryption](https://docs.wordplus.cloud/apps/encryption/#your-local-key)) |
| `profile` | `fn ( $user ) => [ 'name' => …, 'avatar' => …, 'url' => … ]` or `null`: the user directory entry, and by default their presence info and call info |
| `hidden` | `fn ( $user ) => true` for a user never shown online |
| `has_keys` | `fn ( $user ) => false` cuts a user off every key |
| `generations` | a `GenerationStore`, for `kick()`, `rotateRoom()` and `rotateUser()` |
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

## Rules

| Method | |
|---|---|
| `room( $pattern, fn ( $user, array $p, $room ) => $answer, $options = [] )` | rooms by pattern (`order:{id}`): who may join, answered `false`, `true`, or `[ 'info' => …, 'emit' => …, 'readOnly' => … ]`. `$options`: `presence`, `count`, `history` (a number, or `[ 'last', 'days' ]`), `emit` and `webhook` (names, or `'*'`), `public`, `seal`. A public room may have no rule ([rooms](https://docs.wordplus.cloud/apps/rooms/)) |
| `calls( $pattern, fn ( $user, $with, array $p, $call, array $side ) => $answer, $options = [] )` | calls between two people, `$side['role']` `caller` or `callee`. Answers `false`, `true`, or `[ 'info', 'publish', 'subscribe', 'admin', 'hidden' ]`. `$options['people']`: `fn ( $user, $call ) => [ user ids ]`, whom the call screen offers to add ([calls](https://docs.wordplus.cloud/apps/calls/)) |
| `groupCalls( $pattern, fn ( $user, array $p, $name ) => $answer, $options = [] )` | group calls. `$options['encrypt']` encrypts their rooms end to end |
| `streams( $pattern, fn ( $user, array $p, $name ) => $answer, $settings = [] )` | live streams: `false`, a role (`host`, `viewer`), or `[ 'role', 'info', 'chat', 'mod', 'src', 'quality' ]`. A visitor (`$user` null) is a viewer at most. `$settings`: `reactions`, `people`, `chat` (`offline`, `links`, `fresh`), each a value or a closure of the parts of the name ([live streams](https://docs.wordplus.cloud/apps/streams/#who-is-who)) |
| `kind( $kind, fn ( $rest, $user, $label ) => bool, $newest = null )` | keys of your own kind ([encryption](https://docs.wordplus.cloud/apps/encryption/#your-own-sealed-values)) |
| `fallback( fn ( $user, $room ) => $answer, fn ( $room ) => $options )` | the rooms no pattern owns, and what they are. In Laravel, `routes/channels.php` |
| `on( $type, fn ( array $event ) => … )` | the events of a [webhook](https://docs.wordplus.cloud/apps/sending/#webhooks): the name of a browser event, `room.occupied`, `room.vacated`, `member.joined`, `member.left`, `user.online`, `user.offline`, `stream.live`, `stream.ended`, `call.ended`, `group.joined`, `group.left`, `stream.joined`, `stream.left`, or `'*'` |

`{name}` matches one or more characters other than `:` and `.`. The pattern with the most characters of its own decides, and on a tie the one declared first. Each method returns the instance, so calls chain.

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

The routes take JSON POSTs only, and answer anything else with `415`. A page on another site cannot send JSON without asking first, and it is never allowed to. A refusal is part of the answer (`denied`), not an HTTP error.

| Method | What it answers |
|---|---|
| `authorize( $rooms, $user )` | `[ 'token', 'exp', 'keys', 'denied' ]` |
| `authorizeCall( $request, $user )` | `[ 'token', 'exp', 'key', 'media_key', 'pair', 'member', 'ring'? ]` for a call, `[ 'token', 'exp', 'media_key'? ]` for a group call, `[ 'people' ]`, `[ 'may' ]` (whom the user may call, as the online list asks), or `[ 'denied' => … ]` |
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
| `batch( [ [ 'rooms', 'users', 'event', 'data', 'except', 'seal' ], … ] )` | 100 events a request |
| `in( $room )` | `->count()`, `->members()` (of a presence room, their info opened), `->history( [ 'before', 'after', 'limit', 'days' ] )`, `->forget( $id = null )`, `->kick( $user )` |
| `online()` | `->has( $user )`, `->states( $users )`, `->count()`, `->list( $cursor, $limit )` |
| `listRooms( [ 'prefix', 'cursor', 'limit' ] )` | `[ 'count', 'rooms' => [ [ 'name', 'count', 'connections' ] ], 'next'? ]`: the rooms someone is in now ([sending](https://docs.wordplus.cloud/apps/sending/#the-rooms-in-use)) |
| `user( $id )` | `->get()`, `->disconnect()`, `->ask( $event, $data, [ 'timeout', 'all', 'seal' ] )`: their state, out of everything at once, and their pages asked ([a user](https://docs.wordplus.cloud/apps/sending/#a-user)) |
| `usage()` | `[ 'connections' => [ 'now', 'limit' ], 'credits' ]` ([what your app uses](https://docs.wordplus.cloud/apps/sending/#what-your-app-uses)) |
| `webhooks()` | `->deliveries()`, `->resend( $id )`, `->test()` ([deliveries](https://docs.wordplus.cloud/apps/sending/#deliveries-a-resend-and-a-test)) |
| `listCalls()`, `call( $name )` | the group calls going on, and `->get()`, `->mute( $user, $track )`, `->permit( $user, [ 'publish' => true ] )`, `->remove( $user )`, `->end()` ([calls](https://docs.wordplus.cloud/apps/calls/#from-your-server)) |
| `listStreams()`, `stream( $name )` | the streams live now, and `->get()`, `->people()`, `->chat( $limit )`, `->say( $text, $name, $avatar )`, `->deleteMessage( $id )`, `->clearChat()`, `->pin( $id )`, `->slow( $seconds )`, `->timeout( $user, $seconds )`, `->untimeout( $user )`, `->kick( $user )`, `->ban( $user )`, `->unban( $user )`, `->bans()`, `->reactions( $on )`, `->end()` ([live streams](https://docs.wordplus.cloud/apps/streams/#from-your-server)) |
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
