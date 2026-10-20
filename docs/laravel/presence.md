# Presence in Laravel

Three ways to show people: a presence room lists who is in it to everyone in it (who is in the shop, who is viewing a document). A count says how many, at any size. And who is online is known across your whole app, for a dot on every avatar.

![The browsers of Ann, Ben and Cara, each listing the three of them in the shop now, with their roles from the info of each member](https://docs.wordplus.cloud/img/apps/presence-shop.webp)

## A presence room: `routes/channels.php`

```php
// Who is in the shop, PresenceChannel( 'shop' ): everyone signed in, with their name and role.
Broadcast::channel( 'shop', fn ( User $user ) => [ 'name' => $user->name, 'role' => $user->role ] );

// The people of a room: those who may enter, or false.
Broadcast::channel( 'room.{room}', fn ( User $user, Room $room ) => $user->canEnter( $room ) ? [ 'name' => $user->name ] : false );
```

- **An array** lets the user in, as the member info: what the others see of them, up to 4 KB, sealed with the room key. WordPlus Cloud lists the members without reading it.
- **`true`**, in a room declared `[ 'presence' => true ]`, lets them in with your profile ([your users](encryption.md#your-users)).
- **A member** is the user, by their id as a string. A user with several tabs or devices is one member: they leave when their last connection does.
- **Up to 1,000 members** are listed in full, with `join` and `leave`. Past that, the room counts them, and `members()` pages through them, up to 100,000.

## The page

With Laravel Echo, as the page of [getting started](getting-started.md#4-the-page) has it:

```js
Echo.join( 'shop' )
    .here( ( members ) => list( members ) )              // [ { name, role }, … ], as routes/channels.php answered
    .joining( ( member ) => add( member ) )
    .leaving( ( member ) => remove( member ) );

Echo.leave( 'shop' );                                    // stepping out: the others see you go
```

Or with the client itself, which has the ids too:

```js
const shop = rt.join( 'shop' );

const draw = () => list( [ ...shop.members ] );       // [ [ id, { name, role } ], … ]
shop.on( 'joined', draw );
shop.on( 'join', ( { id, info } ) => draw() );
shop.on( 'leave', ( { id } ) => draw() );

// Stepping out: leave the room, and the others see you go.
rt.leave( 'shop' );
```

A move to another server shows nobody leaving.

## Counting

`[ 'count' => true ]` gives a room a live count, without a list: `product.on( 'count', ( n ) => … )`, at most once a second. A presence room counts too.

## Who is online, across your app

Every page whose user your app vouched for makes that user online. They go offline 10 seconds after their last page closes, so a reload does not flicker.

```js
rt.online.watch( [ 34, 43 ] ).on( 'change', ( { id, online, at } ) => dot( id, online ) );
rt.online.has( 34 );          // true or false, for a user watched
rt.online.on( 'count', ( n ) => showOnline( n ) );
```

```php
Realtime::online()->has( 42 );   // and ->count(), ->list()
```

A page watches up to 1,000 users. A user who chose not to show their status is never online to anyone, in the `boot()` of a service provider:

```php
use WordPlus\Realtime\Laravel\RealtimeServiceProvider;

RealtimeServiceProvider::hiddenUsing( fn ( $id ) => (bool) User::find( $id )?->hide_status );
```

## Ready-made: who is online

Optional, as every ready-made part: the presence room above is all a list needs, drawn your way ([your own interface](https://docs.wordplus.cloud/apps/interface/#who-is-in-a-room)). This is the ready-made one.

The Better Messages list of members, on a presence room, with a call button for each of the others. It is one element:

```html
<online-list room="shop" title="In the shop now" call="support:{pair}" type="video"></online-list>
<online-count room="product.7"></online-count>
```

```js
await rt.callScreen();   // loads the elements and the call screen, rt.ui() the elements without calls
```

- **`call`** names the call the button of each row places: `{pair}` is the two user ids in order (`support:34:42`), `{me}` and `{id}` each one. Without it, the rows have no button.
- **Only those the user may call** get a button: the list asks your calls route once for all its members, which runs your `calls()` rule for each as for the caller. Nothing to add on your server.
- **Names and pictures** come from the member info, then from your [directory](encryption.md#your-users).
- **Its look** is that of Better Messages, in a shadow root: your styles do not reach in, and its colours are CSS custom properties your page sets.

More, with Node and Python side by side: [presence in depth](https://docs.wordplus.cloud/apps/presence/).
