# Presence in Laravel

Three ways to show people: a presence room lists who is in it to everyone in it (who is in the shop, who is viewing a document). A count says how many, at any size. And who is online is known across your whole app, for a dot on every avatar.

![The browsers of Ann, Ben and Cara, each listing the three of them in the shop now, with their roles from the info of each member](https://docs.wordplus.cloud/img/apps/presence-shop.webp)

## A presence room: `routes/channels.php`

One room for the whole shop, named `shop`, and one for each room people may enter, such as `room.7`, list who is in them to everyone in them. A page that joins one asks your app first, and a rule answers: the callback of the channel whose pattern fits. WordPlus Cloud then holds the page to the answer ([permissions](https://docs.wordplus.cloud/apps/permissions/)). These rules say:

1. **Visitors** who are not signed in stay out: a visitor never reaches the callback of a channel.
2. **The shop** lets in everyone signed in, each shown to the others with their name and role.
3. **A room** lets in those who may enter it, each shown with their name, and nobody else.

```php
// 2. Who is in the shop, PresenceChannel( 'shop' ): everyone signed in.
Broadcast::channel( 'shop', function ( User $user ) {
    // 1. A visitor never gets here.
    return [ 'name' => $user->name, 'role' => $user->role ];
} );

// 3. The people of a room, PresenceChannel( 'room.7' ): those who may enter, and nobody else.
Broadcast::channel( 'room.{room}', function ( User $user, Room $room ) {
    // 1. A visitor never gets here.
    if ( ! $user->canEnter( $room ) ) {
        return false;
    }

    return [ 'name' => $user->name ];
} );
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

`[ 'count' => true ]` gives a room a live count, without a list. The page of product 7 joins `product.7`, a public room: anyone may join it, signed in or not, and no rule runs ([rooms](rooms.md)). The page hears it with `product.on( 'count', ( n ) => … )`, at most once a second. A presence room counts too.

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

`RealtimeServiceProvider::hasKeysUsing( fn ( $id ) => … )` the same way cuts a user off from the keys, such as a banned one: false gives them no keys and no identity. Everyone has them by default.

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

## Use cases

Each with the rules of your server and the code of the page:

- [Who is online and who is here](https://docs.wordplus.cloud/use-cases/online/): a dot on every avatar, the people in a room, and a live count
- [Editing together](https://docs.wordplus.cloud/use-cases/collaboration/): who is in a document, their cursors and their changes

More, with Node and Python side by side: [presence in depth](https://docs.wordplus.cloud/apps/presence/).
