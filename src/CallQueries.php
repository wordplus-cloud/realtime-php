<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** A group call as the media server holds it. */
final class CallQueries
{
    private readonly string $path;

    public function __construct(
        private readonly Realtime $realtime,
        public readonly string $name,
    ) {
        $this->path = '/wordplus/realtime/calls/'.rawurlencode($name);
    }

    /**
     * Its people and what each sends now (`camera`, `microphone`, `screen`), with what the call holds of each (`held`:
     * `publish`, `mic`), its `rules` and the users with a hand up (`hands`). With nobody in it, `people` is 0,
     * `started_at` null and `list` empty.
     *
     * @return array{name: string, people: int, started_at: ?int, rules?: array{mode: string, locked: bool, muted: bool}, hands?: list<string>, list: list<array{user: ?string, joined_at: int, camera: bool, microphone: bool, screen: bool, held?: array{publish?: bool, mic?: string}}>}
     */
    public function get(): array
    {
        return (array) ($this->realtime->request('GET', $this->path)['call'] ?? []);
    }

    /**
     * Turns someone's microphone off, or their `camera` or `screen`, once: they may turn it on again. With `hold` (a
     * microphone only, the call screen's Mute Microphone) the call keeps it off until `unmute()` or the call ends,
     * through a reload and a token signed later too. `by`, the host it is done for, is named in the `call.moderated`
     * webhook. Whether it was on.
     *
     * @param array{hold?: bool, by?: int|string} $options
     */
    public function mute(int|string $user, string $track = 'microphone', array $options = []): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/mute', ['user' => (string) $user, 'track' => $track] + self::acted($options))['muted'] ?? false);
    }

    /**
     * Lets go of a microphone held off (`mute( $user, 'microphone', [ 'hold' => true ] )`, or Mute Everyone): they may
     * turn it on again, until the next Mute Everyone. Whether they are in the call now.
     *
     * @param array{by?: int|string} $options
     */
    public function unmute(int|string $user, array $options = []): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/unmute', ['user' => (string) $user] + self::acted($options))['changed'] ?? false);
    }

    /**
     * Takes someone out now, a kick: their tokens signed before do not take them back in, and one your server signs
     * after does, unless the call is locked, which keeps them out. Whether they were in it.
     *
     * @param array{by?: int|string} $options
     */
    public function remove(int|string $user, array $options = []): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/remove', ['user' => (string) $user] + self::acted($options))['removed'] ?? false);
    }

    /**
     * Takes someone out and keeps them out, whatever token they bring, for `minutes` (at most a week), or until the call
     * ends when left out: their join is refused `banned`. A host's place is never banned (`forbidden`). Whether they
     * were in it, and until when the ban holds (milliseconds), null until the call ends.
     *
     * @param array{minutes?: int, by?: int|string} $options
     *
     * @return array{removed: bool, until: ?int}
     */
    public function ban(int|string $user, array $options = []): array
    {
        $answer = $this->realtime->request('POST', $this->path.'/ban', ['user' => (string) $user] + self::acted($options));

        return ['removed' => (bool) ($answer['removed'] ?? false), 'until' => isset($answer['until']) ? (int) $answer['until'] : null];
    }

    /**
     * Lets someone banned in again. Whether they were banned.
     *
     * @param array{by?: int|string} $options
     */
    public function unban(int|string $user, array $options = []): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/unban', ['user' => (string) $user] + self::acted($options))['unbanned'] ?? false);
    }

    /**
     * Who is banned from it now, and until when (milliseconds), null until the call ends.
     *
     * @return list<array{user: string, until: ?int}>
     */
    public function bans(): array
    {
        return (array) ($this->realtime->request('GET', $this->path.'/bans')['bans'] ?? []);
    }

    /**
     * Changes what someone may do while it runs: `publish` to make a listener a speaker or a speaker a listener again,
     * which stops what they send, `subscribe` to stop or start getting the others. At once for them when they are in it,
     * and their page hears `permissions`. Their tokens signed before follow it too. Your rule decides every join after,
     * so keep it there as well, and a `publish` held before goes. With `hold` (the call screen's Make Listener)
     * `publish` holds for every join after too, until the other one, a mode switch or the call ends. Whether they were
     * in it.
     *
     * @param array{publish?: bool, subscribe?: bool, hold?: bool, by?: int|string} $can
     */
    public function permit(int|string $user, array $can): bool
    {
        $body = ['user' => (string) $user];

        foreach (['publish', 'subscribe'] as $part) {
            if (isset($can[$part])) {
                $body[$part] = (bool) $can[$part];
            }
        }

        return (bool) ($this->realtime->request('POST', $this->path.'/permit', $body + self::acted($can))['changed'] ?? false);
    }

    /**
     * Sets the call's rules, any of them, which hold until the call empties or ends: `mode` `broadcast` (only hosts send,
     * and those promoted with `permit( $user, [ 'publish' => true, 'hold' => true ] )`) or `participatory`, `locked`
     * (only those already in get back in) and `muted` (Mute Everyone: every microphone but a host's held off). A mode
     * switch lets go of every held `publish`, and Mute or Unmute Everyone of every held microphone. The rules now.
     *
     * @param array{mode?: 'broadcast'|'participatory', locked?: bool, muted?: bool} $change
     * @param array{by?: int|string}                                                  $options
     *
     * @return array{mode: string, locked: bool, muted: bool}
     */
    public function rules(array $change, array $options = []): array
    {
        $body = array_intersect_key($change, ['mode' => true, 'locked' => true, 'muted' => true]);

        return (array) ($this->realtime->request('POST', $this->path.'/rules', $body + self::acted($options))['rules'] ?? []);
    }

    /** Raises or lowers someone's hand, kept until lowered or they leave. Whether it changed. */
    public function hand(int|string $user, bool $up): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/hand', ['user' => (string) $user, 'up' => $up])['changed'] ?? false);
    }

    /**
     * Lowers every hand. How many were up.
     *
     * @param array{by?: int|string} $options
     */
    public function lowerHands(array $options = []): int
    {
        $body = self::acted($options);

        return (int) ($this->realtime->request('POST', $this->path.'/hands/lower', [] === $body ? null : $body)['lowered'] ?? 0);
    }

    /**
     * Asks someone in it to turn on their camera or microphone, for `$by`, who asks: their page hears it, and answers
     * `$by` alone. Nothing is kept. The same ask again within 30 s is refused `asked_recently`, and someone not in the
     * call `not_in_call`. The ask's id and the devices asked.
     *
     * @param list<'camera'|'microphone'> $devices
     *
     * @return array{id: string, devices: list<string>}
     */
    public function ask(int|string $user, array $devices, int|string $by): array
    {
        $answer = $this->realtime->request('POST', $this->path.'/ask', ['user' => (string) $user, 'devices' => array_values($devices), 'by' => (string) $by]);

        return ['id' => (string) ($answer['id'] ?? ''), 'devices' => (array) ($answer['devices'] ?? [])];
    }

    /**
     * A host act's `hold`, `minutes` and `by`, each only when given, so a call without them sends what 1.1.0 sent.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function acted(array $options): array
    {
        $body = [];

        if (isset($options['hold'])) {
            $body['hold'] = (bool) $options['hold'];
        }

        if (isset($options['minutes'])) {
            $body['minutes'] = $options['minutes'];
        }

        if (isset($options['by'])) {
            $body['by'] = (string) $options['by'];
        }

        return $body;
    }

    /** Lets a listener speak, as a host does on the page: `permit( $user, [ 'publish' => true ] )`. */
    public function letSpeak(int|string $user): bool
    {
        return $this->permit($user, ['publish' => true]);
    }

    /** Makes a speaker a listener again, which stops what they send: `permit( $user, [ 'publish' => false ] )`. */
    public function makeListener(int|string $user): bool
    {
        return $this->permit($user, ['publish' => false]);
    }

    /** Puts someone on the main tiles for everyone in it, as a host does on the page. */
    public function spotlightOn(int|string $user): bool
    {
        return $this->spotlight($user, true);
    }

    /** Takes someone off the main tiles, or everyone with no user. */
    public function spotlightOff(int|string|null $user = null): bool
    {
        return $this->spotlight($user, false);
    }

    /**
     * Puts someone on the main tiles for everyone in it (`$on`), up to four (a fifth is refused `spotlight_full`), or
     * takes them off, or everyone with `$user` null. The room keeps it: a page that joins later sees it too, and every
     * page in it hears `spotlight`. Whether it changed anything.
     */
    public function spotlight(int|string|null $user, bool $on): bool
    {
        $body = null === $user ? ['on' => $on] : ['user' => (string) $user, 'on' => $on];

        return (bool) ($this->realtime->request('POST', $this->path.'/spotlight', $body)['changed'] ?? false);
    }

    /**
     * Ends it for everyone. Tokens signed before do not open it again, and what it held goes: its rules, hands, held
     * acts and the bans until it ends. A ban given minutes lasts its minutes. A join after it starts a new call.
     *
     * @param array{by?: int|string} $options
     */
    public function end(array $options = []): void
    {
        $body = self::acted($options);
        $this->realtime->request('POST', $this->path.'/end', [] === $body ? null : $body);
    }
}
