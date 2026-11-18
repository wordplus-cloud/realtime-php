# Calls in Laravel

Calls between two people that ring in every tab and device the callee has open, group calls on the WordPlus Cloud media servers, and the Better Messages call screen: the buttons, the ring and the call window, with no call code. The media of a call is encrypted end to end, with keys your app gives out.

- **A call between two people** connects on three paths, tried in turn: directly between the two devices, through our relays (TURN) on the ports of the web, 443 and 80, when a network blocks that, and through our media servers when even the relays cannot reach. It moves from one to the next without hanging up, and takes no credits on any path.
- **Group calls** have no limit on people. The people on video up to the floor of your plan take no credits, a person on audio counting half, and each one above takes a credit a minute from your account ([limits](https://docs.wordplus.cloud/apps/limits/)).
- **How a call and a group call start, run and end:** [how calls work](https://docs.wordplus.cloud/use-cases/how-it-works/).
- **Step by step:** [calls from a button](https://docs.wordplus.cloud/use-cases/support-calls/), [team meetings](https://docs.wordplus.cloud/use-cases/meetings/), [consultations by booking](https://docs.wordplus.cloud/use-cases/consultations/), [classes, webinars and audio rooms](https://docs.wordplus.cloud/use-cases/classes/), and [waiting rooms and breakout rooms](https://docs.wordplus.cloud/use-cases/waiting-rooms/).

![Ben has pressed a video call button: his browser shows the call window calling Ann, over his own camera, and the browser of Ann rings, with buttons to answer and decline](https://docs.wordplus.cloud/img/apps/calls-ringing.webp)

## Your first call

On top of [getting started](getting-started.md), a call is three pieces: a controller of yours that answers a token for the signed-in user, the call screen, and a button that asks it. Here anyone signed in may call anyone, which [who may call](#who-may-call) narrows down.

```php
// app/Http/Controllers/CallTokenController.php: GET /call-token?to=34&type=video, which the button asks as it is pressed.
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use WordPlus\Realtime\Laravel\Facades\Realtime;

class CallTokenController extends Controller
{
    public function __invoke( Request $request )
    {
        abort_unless( $request->user(), 401 );

        return [ 'token' => Realtime::callToken( $request->user()->id, (string) $request->query( 'to' ), [
            'type' => $request->query( 'type' ),
        ] ) ];
    }
}
```

```php
// routes/web.php
Route::get( '/call-token', CallTokenController::class );
```

```js
// resources/js/app.js, after Echo: its connection is the page's
await window.Echo.connector.rt.callScreen();   // the ring and the call window, on every page a call may ring on
```

```blade
<call-button to="34" token-url="/call-token" type="video" name="Ann">Call Ann</call-button>
```

Sign in as user 42 in one browser and as Ann, user 34, in another, and press the button: it asks your controller, Ann's browser rings, and once she answers, each sees the other. The rest of this page is the rest of it: who may call, [group calls](#group-calls), [a call interface of your own](#your-own-call-interface), and [the other way, by a rule](#by-a-rule).

## Who may call

Your app decides, in its own controller: the button asks it for a token, your controller checks whatever your app needs, and answers a token or refuses. The person called answers with no token of their own, as the token that rang them proves your app said yes. With no token from your app nothing starts, so nobody gets around your controller ([permissions](https://docs.wordplus.cloud/apps/permissions/#calls-between-two-people)).

These controllers say:

1. **Someone signed in** calls: a visitor is refused.
2. **One of the two is staff:** customers call staff, and staff call anyone.
3. **The daily meeting,** `meeting:daily`, a group call, is hosted by staff: they mute the others, let them speak or make them listeners, and take them out.
4. **Everyone else signed in** joins it.

```php
// app/Http/Controllers/CallTokenController.php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use WordPlus\Realtime\Laravel\Facades\Realtime;

class CallTokenController extends Controller
{
    // GET /call-token?to=34&type=video
    public function __invoke( Request $request )
    {
        // 1. Someone signed in.
        $me = $request->user();
        abort_unless( $me, 401 );

        // 2. One of the two is staff.
        $to = (string) $request->query( 'to' );
        abort_unless( $me->staff || User::find( $to )?->staff, 403 );

        return [ 'token' => Realtime::callToken( $me->id, $to, [ 'type' => $request->query( 'type' ) ] ) ];
    }

    // GET /meeting-token?id=daily, for the meeting meeting:daily.
    public function meeting( Request $request )
    {
        // 4. Everyone signed in joins it.
        $me = $request->user();
        abort_unless( $me, 401 );

        // 3. Staff host it.
        return [ 'token' => Realtime::groupCallToken( $me->id, 'meeting:' . $request->query( 'id' ), [
            'type'  => $request->query( 'type' ),
            'admin' => (bool) $me->staff,
        ] ) ];
    }
}
```

```php
// routes/web.php
Route::get( '/call-token', CallTokenController::class );
Route::get( '/meeting-token', [ CallTokenController::class, 'meeting' ] );
```

- **What `callToken()` takes** beside the two people, in its options array: `type`, `audio` or `video`, as the button asked; `name`, a name of your own for the call, which [`call.ended`](sending.md#webhooks) and the other webhooks give back and [a spot in the page](#the-call-in-the-page) takes, `call:` and a random id by default; `info`, what the person called sees of the caller, your profile by default; and `into`, to ring someone into a call that is up ([adding people](#adding-people)).
- **What `groupCallToken()` takes** beside the user and the name: `type`, `publish` and `subscribe` (whether they send and receive), `admin`, `hidden` (listed to nobody), `encrypt` and `info` ([group calls in depth](https://docs.wordplus.cloud/apps/calls/#group-calls)).
- **A token rings once,** so the button asks for a new one each time. It is for its user: on anyone else's page it starts nothing (`forbidden`). It lasts 6 hours at most (`call_token_ttl`). Someone taken out of a group call needs a new one to come back, so your controller is asked again.
- **To refuse,** answer 401 or 403, as `abort_unless()` does: the button rings nobody, opens no window, and tells the person they cannot make this call.
- **`admin`** makes them its host, who mutes the others, lets them speak or makes them listeners, and takes them out, on a proof in the token that only your app makes ([permissions](https://docs.wordplus.cloud/apps/permissions/#group-calls)).
- **A host in the call screen** has the host buttons on the tile of each of the others: Manage Participant opens Mute Microphone, Stop Camera & Mic and Allow Camera & Mic (Demote to Viewer and Promote to Speaker in an audio call), Spotlight for Everyone or Remove Spotlight in a video call, up to four people, and Remove from Call, with Clear Spotlight in Host Controls.
- **A host on a page of your own** does the same with `group.mute( user )`, `group.letSpeak( user )`, `group.makeListener( user )`, `group.remove( user )`, `group.spotlightOn( user )` and `group.spotlightOff( user )` ([your own call interface](#your-own-call-interface)).
- **Your app** does it with no page open: `Realtime::call( $name )->mute()`, `->permit()`, `->remove()`, `->spotlight()` and `->end()` ([from your server](#from-your-server)).
- **The media of a group call** your app names is encrypted end to end when its tokens say `'encrypt' => true`. The group calls that come from a call always are.
- **User ids** are yours: a number, or text such as `user_2abc` or `auth0|5f7c`, up to 64 characters with no space.

### By a rule

The other way, for an app that would rather declare who may call whom once: a rule, a function your app declares in a service provider for every call whose name fits a pattern, as it declares rooms. The button then names the call in place of a route, `<call-button to="34" call="support:{pair}">`, and the browser calls route that comes with the package (`/wordplus/realtime/calls`) asks the rule as someone calls, answers or joins. Everything else stays the same: the call screen and its buttons, the ring, the paths a call connects on, its encryption, the host and what a host does, `Realtime::call( $name )`, and credits. The two ways mix: a `calls()` rule that owns the name of a call is still asked as someone answers it.

The same four as rules, where the name of a call carries its people, `support:34:42`, in the `boot()` of a service provider:

```php
use App\Models\User;
use WordPlus\Realtime\Realtime;

public function boot(): void
{
    // Added when the SDK is first used: artisan commands then run without the app credentials.
    $this->callAfterResolving( Realtime::class, function ( Realtime $realtime ) {
        $staff = fn ( $id ) => (bool) User::find( $id )?->staff;

        // A support call, such as support:34:42, for the two people in its name. User 42 is "42" there, user_2abc itself.
        $realtime->calls( 'support:{a}:{b}', function ( $user, $with, array $p ) use ( $staff ) {
            $pair = [ $p['a'], $p['b'] ];
            if ( ! in_array( (string) $user, $pair, true ) || ! in_array( (string) $with, $pair, true ) ) {
                return false;
            }

            // 2. One of the two is staff.
            return $staff( $user ) || $staff( $with );
        } );

        // The daily meeting, meeting:daily: 3. staff host it, 4. everyone else signed in joins it.
        $realtime->groupCalls( 'meeting:{id}', fn ( $user ) => $staff( $user ) ? [ 'admin' => true ] : true );
    } );
}
```

- **A page with nobody signed in** is refused before any rule runs.
- **`$user` and `$with` are ids**, not models. `$with` is the other person: whom `$user` calls, or who calls `$user`. Your app is asked again when the callee answers, with the callee as `$user`.
- **`$call` and `$side`**, the fourth and fifth arguments, are the name of the call and which side asks: `$side['role']` is `caller` or `callee`. A rule that says yes to every callee and checks only callers lets people answer calls they may not place.
- **The answer:** `false`, `true`, or an array: `info` (what the others see of `$user`: your profile by default), and in a group call `publish`, `subscribe`, `admin` and `hidden`. For a host's acts, the calls route asks this rule again, and does them only for a host.
- **Encrypted end to end:** `groupCalls( 'meeting:{id}', $rule, [ 'encrypt' => true ] )`.
- **User ids in a rule:** an id of digits comes as a number and any other as text, so compare them with the name as text. An id calls cannot take is refused with `no_user`.

## The call screen

Optional, as every ready-made part: [your own call interface](#your-own-call-interface) below places, answers and draws calls with no element, and [your own interface](https://docs.wordplus.cloud/apps/interface/#calls) has the whole of it.

Start it on every page where a call may ring, and put buttons where people call from:

```js
// resources/js/app.js, after Echo: its connection is the page's
await window.Echo.connector.rt.callScreen();   // the ring and the call window, on this page
```

```blade
<call-button to="{{ $agent->id }}" token-url="/call-token" type="video" name="{{ $agent->name }}" avatar="{{ $agent->avatar }}">Call {{ $agent->name }}</call-button>

<group-call-button call="meeting:daily" token-url="/meeting-token?id=daily" type="video" title="Daily meeting">Join the meeting</group-call-button>
```

- **`token-url`** is your controller's route ([who may call](#who-may-call)). A press asks it with a GET, with the page's cookies, and reads `{ token }`: `<call-button>` sends `?to=34&type=video`, and `<group-call-button>` adds `call` and `type` to what you wrote, `?id=daily&call=meeting:daily&type=video`.
- **By a rule,** `call="support:{pair}"` in place of `token-url`, and `call` alone on `<group-call-button>` ([by a rule](#by-a-rule)). `{pair}` is filled in with the page user and the one called, in order, so whichever of the two calls names the same call: `support:34:42`.
- **A list with call buttons:** `<online-list room="shop" token-url="/call-token">` puts one on the row of every member, asking your controller as it is pressed ([presence](presence.md#ready-made-who-is-online)). By a rule, `call="support:{pair}"`.
- **Its words** come in English, German, Spanish, French, Italian, Dutch, Polish, Croatian and Hebrew, by the page `<html lang="{{ app()->getLocale() }}">`. `rt.callScreen( { strings: { … } } )` changes any of them.
- **Its look** is that of Better Messages, in a shadow root: your styles do not reach in, and its colours are CSS custom properties your page sets.
- **Group calls** load the room client and its encryption worker from the package routes, which `@wordplusRealtime` names: nothing to add.
- **Leaving during a call** asks first, in the browser's own words: another page, a reload or a closed tab would end it, as every page of yours loads anew.

![Ann has answered: each browser shows the other person camera, their own in a corner, and the call controls. The cameras are illustrations](https://docs.wordplus.cloud/img/apps/calls-connected.webp)

## The call in its chat

A conversation between two can hold its own call: the other person on the chat's top bar with a video and an audio call button, and their call in the chat itself while it is on, beside the messages. Give the chat the other person, with their name and picture as your page has them, and `token-url`, your route for its call buttons:

```blade
<chat-room room="chat:34:42" to="{{ $member->id }}" name="{{ $member->name }}" avatar="{{ $member->avatar }}" token-url="/chat-call-token" style="height: 560px"></chat-room>
```

Its call buttons ask your route with the name of the chat as well, `?to=42&type=video&name=chat:34:42`. Your controller puts that name on the token, so the call shows in the chat. The name comes from the page, so check it:

```php
// In CallTokenController, for Route::get( '/chat-call-token', [ CallTokenController::class, 'chat' ] ).
// Chat is your own model, found by the name of its room, with hasMember() of your own.
public function chat( Request $request )
{
    $me = $request->user();
    $to = (string) $request->query( 'to' );
    $chat = Chat::where( 'room', (string) $request->query( 'name' ) )->first();

    // The person calling and the person called are both members of that chat.
    abort_unless( $me && $chat?->hasMember( $me->id ) && $chat->hasMember( $to ), 403 );

    // The call takes the name of the chat, so it shows there.
    return [ 'token' => Realtime::callToken( $me->id, $to, [ 'type' => $request->query( 'type' ), 'name' => $chat->room ] ) ];
}
```

Narrower than 600px, the call takes the whole chat, and Show Chat in its controls puts the messages under it. `start-call="video"` calls them once as the chat opens, for a Video button elsewhere that links to the chat ([`<chat-room>`](https://docs.wordplus.cloud/apps/elements/#chat-room)). By a rule, `call` with no `token-url` is the call named as the room, which your calls rule for `chat:{a}:{b}` decides, as the rule for `support:{a}:{b}` above.

## The call in the page

The call screen opens a call in a window in the corner, over the page. To show it in the page itself, above a chat or in place of a picture, put a `<call-spot>` where it goes, naming the calls it takes:

```html
<call-spot call="support:{a}:{b}" style="height: 480px"></call-spot>
```

A call shows there as it starts, until it ends or the page closes. `call` takes the name your token gives the call (`name`) or a rule's name, `support:34:42`, or a pattern of names. With no call, the spot takes no place, or shows what you put inside it ([`<call-spot>`](https://docs.wordplus.cloud/apps/elements/#call-spot)).

## Group calls

A group call runs on our media servers, with no limit on people: a name your controller answers a token for with `groupCallToken()`, as `meeting:daily` above, the token saying what this person may do in it ([who may call](#who-may-call)). It has no setup of its own: it starts as the first person joins. A page joins it with a button that asks your controller, and the call screen draws it:

```blade
<group-call-button call="meeting:daily" token-url="/meeting-token?id=daily" type="video" title="Daily meeting">Join the meeting</group-call-button>
```

- **By a rule,** a `groupCalls()` rule says who joins and who hosts, and the button names `call` alone ([by a rule](#by-a-rule)).
- **Straight in** as the page opens, with no button: [straight into a meeting](#straight-into-a-meeting).
- **From a call between two:** ringing someone in makes it one ([adding people](#adding-people)).
- **Speakers and listeners,** for a class or an audio room: [speakers and listeners](#speakers-and-listeners).
- **Its host** mutes, lets speak and takes out, from the call screen or a page of your own, and your app with `Realtime::call( $name )` ([from your server](#from-your-server)).
- **A screen of your own:** `rt.joinGroupCall( token )` ([your own call interface](#your-own-call-interface)).

## Straight into a meeting

A meeting room can take people in as they open its page, with no button to press. `join` on the spot joins the group call it names, as its `<group-call-button>` would, asking your controller as the button does, and your controller decides as for the button. Here your server writes it only while the meeting is on, so the first one in still starts it with the button inside:

```php
// routes/web.php
use WordPlus\Realtime\Laravel\Facades\Realtime;

Route::get( '/teams/{team}', function ( Team $team ) {
    $room = "meeting:{$team->id}";
    // Someone is in it now.
    $on = Realtime::call( $room )->get()['people'] > 0;
    return view( 'team', compact( 'team', 'room', 'on' ) );
} );
```

```blade
<call-spot call="{{ $room }}" token-url="/meeting-token?id={{ $team->id }}" @if ( $on ) join="video" @endif>
    <group-call-button call="{{ $room }}" token-url="/meeting-token?id={{ $team->id }}">Start the meeting</group-call-button>
</call-spot>
```

It joins once: someone who leaves, or whom the host takes out, stays out while the page is open, and opening the page again joins again ([straight into a meeting](https://docs.wordplus.cloud/apps/elements/#straight-into-a-meeting)). By a rule, a spot with no `token-url` joins as your `groupCalls()` rule says.

### Camera, microphone and sound

- **Off as they come in.** They come in with the camera and microphone off, and turn on what they want from the call's controls, so the browser asks for neither as the page opens.
- **`join-with="microphone"`,** or `"microphone camera"`, has them on as they come in.
- **Sound.** A browser plays sound only once the person has clicked or typed on the page, or on the page of your site they came from. A page opened straight into a meeting, from a link in a new tab say, shows the others and **Turn sound on** over the call: their voices start at that click, or at any other on the page.

## Adding people

Once a call is up, either side may ring someone else in, and it goes on as a group call, taking credits as one.

- **On a page of your own,** the page sends your controller `call.ref`, which proves this user is in the call, and your controller answers `Realtime::callToken( $me->id, $to, [ 'into' => … ] )` with its own check of whom they may add. `call.add( token )` then rings them in ([adding people](https://docs.wordplus.cloud/apps/calls/#adding-people)).
- **The call screen's Add Person button** lists whom a `calls()` rule for the call's name offers in `people`, the [rule way's](#by-a-rule) part:

```php
$realtime->calls( 'support:{a}:{b}', $maySupport, [
    // The staff not in the call, each still asked of $maySupport.
    'people' => fn ( $user, string $call ) => User::where( 'staff', true )->whereNotIn( 'id', array_slice( explode( ':', $call ), 1 ) )->pluck( 'id' )->all(),
] );
```

The screen then shows an Add Person button, listing them with their profile names and pictures. The person added is asked of your rule as any call: `$user` calling them about the call name, then them taking a call from `$user`.

## Speakers and listeners

A class, a town hall or an audio room: a few speak and everyone else listens. Your token makes the listeners (`publish` false), and your app lets someone speak while the call runs ([classes, webinars and audio rooms](https://docs.wordplus.cloud/use-cases/classes/)). For one person live to a large audience, with guests on stage, see [live streams](streams.md) and [interviews and questions on stage](https://docs.wordplus.cloud/use-cases/interviews/).

The controller of a class, `class:7`, says:

1. **The teacher** speaks, and hosts the class.
2. **Anyone who is not a student** is turned away.
3. **A student** listens until the teacher lets them speak.

`isStudent()` and `maySpeak()` are methods of your own model:

```php
// app/Http/Controllers/ClassTokenController.php: GET /classes/7/token, which
// <group-call-button call="class:7" token-url="/classes/7/token" type="audio"> asks.
namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;
use WordPlus\Realtime\Laravel\Facades\Realtime;

class ClassTokenController extends Controller
{
    public function __invoke( Request $request, Course $course )
    {
        $me = $request->user()?->id;

        // 1. The teacher speaks, and hosts the class.
        if ( null !== $me && $course->teacher_id === $me ) {
            return [ 'token' => Realtime::groupCallToken( $me, "class:{$course->id}", [ 'type' => 'audio', 'admin' => true ] ) ];
        }

        // 2. Anyone who is not a student.
        abort_unless( null !== $me && $course->isStudent( $me ), 403 );

        // 3. A student listens until let speak.
        return [ 'token' => Realtime::groupCallToken( $me, "class:{$course->id}", [ 'type' => 'audio', 'publish' => $course->maySpeak( $me ) ] ) ];
    }
}
```

By a rule, the same three in a `groupCalls( 'class:{id}', … )` rule of the service provider, which answers `[ 'admin' => true ]`, `false` or `[ 'publish' => … ]`.

A Let speak button of your own, in a list of raised hands beside the call, posts to a controller, which:

4. **Takes it from the teacher** alone.
5. **Keeps the choice** where the token controller reads it, for every join after.
6. **Changes it in the call** at once.

```php
// routes/web.php
Route::get( '/classes/{course}/token', ClassTokenController::class );
Route::post( '/classes/{course}/speakers', [ SpeakerController::class, 'update' ] )->middleware( 'auth' );
```

```php
// app/Http/Controllers/SpeakerController.php: the Let speak button of the teacher, with { user, speak }
namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;
use WordPlus\Realtime\Laravel\Facades\Realtime;

class SpeakerController extends Controller
{
    public function update( Request $request, Course $course )
    {
        // 4. The teacher alone.
        abort_unless( $course->teacher_id === $request->user()->id, 403 );

        $student = $request->integer( 'user' );
        $speak = $request->boolean( 'speak' );

        // 5. Kept where the token controller reads it, for every join after.
        $course->setMaySpeak( $student, $speak );

        // 6. At once in the call: their page hears `permissions`.
        Realtime::call( "class:{$course->id}" )->permit( $student, [ 'publish' => $speak ] );

        return response()->noContent();
    }
}
```

- **On the call screen,** the teacher, as host, has Manage Participant on the tile of each student: Promote to Speaker and Demote to Viewer in an audio call, Allow Camera & Mic and Stop Camera & Mic in a video call. They change the call as `permit()` does, through your calls route. Your Let speak controller above also keeps the choice for the joins after.
- **A student let speak** finds the microphone and camera in the call controls. Made a listener again, they go, and what the student sent stops.
- **A token signed for them before** follows the change, so an older one cannot undo it. A token signed after says what your token controller, or your rule, says then, so keep who may speak where it reads it, as `setMaySpeak()` does.
- **Raised hands** are a room of the same name, which carries a `hand` event, and the page of your own call reads `group.can` and `person.publish` ([speakers and listeners](https://docs.wordplus.cloud/apps/calls/#speakers-and-listeners)).

## Your own call interface

The call screen is built on this, and nothing else:

```js
const rt = window.Echo.connector.rt;   // the connection Echo made
const calls = await rt.calls();

// A token from your controller, as the buttons ask it.
async function tokenFrom( url ) {
    const answer = await fetch( url );
    if ( ! answer.ok ) throw new Error( 'Your server said no' );
    return ( await answer.json() ).token;
}

const call = await rt.startCall( await tokenFrom( '/call-token?to=34&type=video' ) );
call.on( 'state', ( state ) => show( state ) );       // ringing, connecting, connected, reconnecting, ended
call.on( 'path', ( path ) => show( path ) );          // direct, relay or room: the path it connects on now
call.on( 'remote', () => call.remote.camera?.attach( video ) );
call.on( 'autoplay_blocked', () => showSoundButton() );   // the browser holds the sound back until a click

calls.on( 'incoming', async ( incoming ) => {
    const answered = await incoming.accept();     // the call, or the group call it rings into. Or incoming.decline( 'busy' )
} );

const meeting = await rt.joinGroupCall( await tokenFrom( '/meeting-token?id=daily&type=video' ) );
meeting.on( 'person', ( person ) => person.camera?.attach( tileFor( person ) ) );
if ( meeting.can.admin ) await meeting.mute( 43 );   // a host: mute, letSpeak, makeListener, remove, spotlightOn, spotlightOff
```

By a rule, `rt.call( 'support:{pair}', { to: 34, type: 'video' } )` and `rt.groupCall( 'meeting:daily', { type: 'video' } )` in place of the tokens ([by a rule](#by-a-rule)).

![Ann, Ben and Cara in one group call from three browsers, with the call screen grid: each sees the other two and their own camera, then the call controls. The cameras are illustrations](https://docs.wordplus.cloud/img/apps/rooms-meeting.webp)

## From your server

Your app sees the group calls going on and runs them, with no page open: from a controller, a job or an artisan command.

```php
use WordPlus\Realtime\Laravel\Facades\Realtime;

Realtime::listCalls();                          // [ [ 'name', 'people', 'started_at' ] ]
$call = Realtime::call( 'meeting:daily' );
$call->get();                                   // [ 'people', 'started_at', 'list' => [ [ 'user', 'joined_at', 'camera', 'microphone', 'screen' ] ] ]
$call->permit( 43, [ 'publish' => true ] );     // a listener let speak, false back to listening
$call->mute( 43, 'microphone' );                // 'camera' or 'screen' too: they may turn it on again
$call->spotlight( 43, true );                   // on the main tiles for everyone, up to four. null, false: nobody
$call->remove( 43 );                            // out now, their tokens from before refused
$call->end();                                   // out, everyone
```

- **A host's buttons** are on the call screen already, for whom your token, or your rule, makes `admin`. Buttons of your own call a controller that checks who asks, then these, as the Let speak controller above.
- **`get()`** with nobody in the call answers `people` 0, `started_at` null and an empty list, as [straight into a meeting](#straight-into-a-meeting) reads it. `started_at` is when this call began: one named again after everyone left begins again.
- **A call between two people** runs between the two, so it is not in `listCalls()`. Once someone is added it is a group call, named `call:` and its id.

### What a call holds

A host runs a group call as in Better Messages, and some acts are held by the call itself, so a reload, a dropped connection or a token signed later does not undo them:

- A mute is once, and the person may turn the microphone on again. Mute Microphone (`'hold' => true`) keeps it off until `unmute()`.
- Make Listener and its promotion (`permit()` with `'hold' => true`) hold until the other one or a switch of Broadcast Mode.
- Remove is a kick, which a locked call keeps out. A ban keeps them out for the minutes given, at most a week, or until the call ends.
- The rules, Mute Everyone, Lock and Broadcast Mode, hold until everyone has left.
- A raised hand stays up until it is lowered or its owner leaves, and an ask keeps nothing. `end()` lets go of everything but a ban given minutes.

A host is never held, and a ban of a host is refused.

```php
$call = Realtime::call( 'meeting:daily' );
$call->mute( 43, 'microphone', [ 'hold' => true ] );          // Mute Microphone, until unmute() or the end of the call
$call->unmute( 43 );                                          // Allow Microphone
$call->permit( 43, [ 'publish' => false, 'hold' => true ] );  // Make Listener, held for every join after
$call->ban( 43, [ 'minutes' => 60, 'by' => 1 ] );             // out for an hour, for host 1. No minutes: until the call ends
$call->unban( 43 );                                           // may join again
$call->bans();                                                // [ [ 'user', 'until' ] ]
$call->rules( [ 'locked' => true ] );                         // the rules now: [ 'mode', 'locked', 'muted' ]
$call->lowerHands();                                          // every hand down. hand( 43, false ) for one
$call->ask( 43, [ 'microphone' ], 1 );                        // for host 1: [ 'id', 'devices' ]
$call->end( [ 'by' => 1 ] );                                  // out, everyone, and all it held goes but a timed ban
```

- **The call screen does these itself** for a host, where the call offers them: the token names the acts this package takes (`acts`), and gives everyone a `member` proof to raise a hand with, so nothing more is needed in your app.
- **`'by'`** names the host you act for, which the `call.moderated` webhook gives back (null for your app). Keep a longer ban there, in your own data ([webhooks](sending.md#webhooks)).
- **`permit()` without `'hold'`** changes the call now, as before, and lets go of a `publish` held before.

Every event, why a call ended, and what WordPlus Cloud sees: [calls in depth](https://docs.wordplus.cloud/apps/calls/).
