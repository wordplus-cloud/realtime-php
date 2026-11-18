# Changelog

## 1.1.0 - 2026-11-18

Every name that worked in 1.0.2 still works.

### New

- **Group calls moderated as in Better Messages.** The calls route takes a host's Ban (for the minutes given, or until
  the call ends), Mute and Allow Microphone held until the call ends, the rules (Mute Everyone, Lock, Broadcast Mode),
  Ask to turn on a camera or microphone and End for Everyone, and everyone's raised hand (`['hand' => …]`). Make
  Listener and its promotion from the call screen now hold until the call ends, while a mute stays once. Every group
  call token carries `acts` and a `member` proof, and the room answer `acts`, so the screen offers only what both this
  package and WordPlus Cloud take. On a call: `ban()`, `unban()`, `bans()`, `unmute()`, `rules()`, `hand()`,
  `lowerHands()` and `ask()`, `mute( …, [ 'hold' => true ] )` and `permit( …, [ 'hold' => true ] )` (no hold by
  default, as before), each taking `'by'`. The `call.moderated` webhook tells your server of each act.
- **You decide who may call.** `callToken()`, `groupCallToken()` and `streamToken()` make the token for a route of your
  own that decides who may call, join or watch. Also `toEveryone()`, `room( $name )->removeEveryone()`,
  `->recheck( $user )` and `->recheckEveryone()`.
- **Spotlight.** A host puts people in the spotlight for everyone through the calls route (up to four; a fifth is refused
  as `spotlight_full`), and takes everyone out by naming nobody. Also on a call: `letSpeak()`, `makeListener()`,
  `spotlightOn()`, `spotlightOff()` and `mute( $user )`.
- **Rooms of two people.** `calls( 'support:{pair}' )` and `room( 'chat:{pair}' )` let in the two people the name holds and
  nobody else, with no rule to write. `room( 'chat:7' )` with only a name is that one room, with `emit()` and `remove()`.
- **The same words as the other packages.** `app` is the name of the app's key (`site` still works), `new Realtime( [ 'app' => ... ] )`
  and `$realtime->app()`. `history` takes `[ 'last' => 50, 'seconds' => 120 ]` (`days` still works). `user( $id )->emit()` as
  a room has it, `except` among any emit's options, and a stream's `remove()`.
- **Bytes in events.** `new Binary( $bytes )` anywhere in an event's data sends bytes, sealed with the rest; a page gets a
  `Uint8Array`.
- **A command.** `vendor/bin/wordplus-realtime init` writes the keys into `.env` with a local key made for the app, changing
  nothing already there, and `check [address]` says whether the keys and routes work and what to fix.
- **Laravel.** The config file sets `rooms_worker`, `headers`, `token_ttl` and `call_token_ttl`, and `hasKeysUsing()` says
  who may have keys. The facade names every method an app calls (`streams()`, `head()`, `mayJoin()` and more), so editors
  know them.
- **Browser files.** The browser files are the newest of `@wordplus/realtime`, with the call screens, effects and streams of its 1.2.0:
  the group call screen offers the moderation above, and `noiseDefault: 'auto'` in the page's call screen settings turns
  AI Noise Removal on for people who never chose (`'off'` stays the default).
- A name no rule owns is said once in the log, with what to declare.

### Changed

- Call and stream tokens say when they were signed to the millisecond (`ms`, beside `iat`), as room tokens do, so someone removed from a call and let back in within the same second gets in once the cloud reads it.
- The directory hash follows the day, so an app whose users keep reconnecting no longer keeps an entry sealed weeks ago.
- A room's `history` takes any number, rounded down: `20.0`, which a config file may hand over, no longer keeps no history.

### Fixed

- A name, user or call id with a newline at its end was accepted (`order:42\n` reached the rule as `42`); it is now refused,
  as the other servers refuse it.
