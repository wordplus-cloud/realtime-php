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
     * Its people and what each sends now (`camera`, `microphone`, `screen`). With nobody in it, `people` is 0,
     * `started_at` null and `list` empty.
     *
     * @return array{name: string, people: int, started_at: ?int, list: list<array{user: ?string, joined_at: int, camera: bool, microphone: bool, screen: bool}>}
     */
    public function get(): array
    {
        return (array) ($this->realtime->request('GET', $this->path)['call'] ?? []);
    }

    /** Turns the `microphone`, `camera` or `screen` of someone off. They may turn it on again. Whether it was on. */
    public function mute(int|string $user, string $track): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/mute', ['user' => (string) $user, 'track' => $track])['muted'] ?? false);
    }

    /** Takes someone out now. Their tokens signed before do not take them back in. Whether they were in it. */
    public function remove(int|string $user): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/remove', ['user' => (string) $user])['removed'] ?? false);
    }

    /**
     * Changes what someone may do while it runs: `publish` to make a listener a speaker or a speaker a listener again,
     * which stops what they send, `subscribe` to stop or start getting the others. At once for them when they are in it,
     * and their page hears `permissions`. Their tokens signed before follow it too. Your rule decides every join after,
     * so keep it there as well. Whether they were in it.
     *
     * @param array{publish?: bool, subscribe?: bool} $can
     */
    public function permit(int|string $user, array $can): bool
    {
        $body = ['user' => (string) $user];

        foreach (['publish', 'subscribe'] as $part) {
            if (isset($can[$part])) {
                $body[$part] = (bool) $can[$part];
            }
        }

        return (bool) ($this->realtime->request('POST', $this->path.'/permit', $body)['changed'] ?? false);
    }

    /** Ends it for everyone. Tokens signed before do not open it again. */
    public function end(): void
    {
        $this->realtime->request('POST', $this->path.'/end');
    }
}
