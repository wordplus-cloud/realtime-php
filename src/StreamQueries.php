<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** A live stream, and what its moderators do, from the server of the app. */
final class StreamQueries
{
    private readonly string $path;

    public function __construct(
        private readonly Realtime $realtime,
        public readonly string $name,
    ) {
        $this->path = '/wordplus/realtime/streams/'.rawurlencode($name);
    }

    /**
     * Its state, how many watch now, its peak, slow mode, reactions and the pinned message.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return (array) ($this->realtime->request('GET', $this->path)['stream'] ?? []);
    }

    /**
     * The signed-in people watching or on it, and how many watch in all, visitors included.
     *
     * @return array{watching: int, people: list<array<string, mixed>>}
     */
    public function people(): array
    {
        $answer = $this->realtime->request('GET', $this->path.'/people');

        return ['watching' => (int) ($answer['watching'] ?? 0), 'people' => array_values((array) ($answer['people'] ?? []))];
    }

    /**
     * The last messages of the chat, oldest first: 50, or `limit` up to 200.
     *
     * @return list<array<string, mixed>>
     */
    public function chat(?int $limit = null): array
    {
        return array_values((array) ($this->realtime->request('GET', $this->path.'/chat'.(null === $limit ? '' : '?limit='.$limit))['messages'] ?? []));
    }

    /**
     * A message from your app in the chat, shown as a host message: its name and an https picture of your own.
     *
     * @return array<string, mixed>
     */
    public function say(string $text, string $name, ?string $avatar = null): array
    {
        $body = ['text' => $text, 'name' => $name];

        if (null !== $avatar) {
            $body['avatar'] = $avatar;
        }

        return (array) ($this->realtime->request('POST', $this->path.'/chat', $body)['message'] ?? []);
    }

    /** Deletes a message for everyone. Whether it was there. */
    public function deleteMessage(string $id): bool
    {
        return (bool) ($this->realtime->request('DELETE', $this->path.'/chat/'.rawurlencode($id))['deleted'] ?? false);
    }

    /** Empties the chat for everyone, the pinned message too. */
    public function clearChat(): void
    {
        $this->realtime->request('DELETE', $this->path.'/chat');
    }

    /** Pins a message over the chat, or none (`null`). Throws `not_found` for a message no longer there. */
    public function pin(?string $id): void
    {
        $this->realtime->request('POST', $this->path.'/pin', ['id' => $id]);
    }

    /** Slow mode: one message every `$seconds` (up to 300) for everyone but its hosts and moderators, 0 turns it off. */
    public function slow(int $seconds): void
    {
        $this->realtime->request('POST', $this->path.'/slow', ['seconds' => $seconds]);
    }

    /** Someone may not write until the end of `$seconds` (60 to 86,400), and goes on watching. When it ends, in ms. */
    public function timeout(int|string $user, int $seconds): int
    {
        return (int) ($this->realtime->request('POST', $this->path.'/timeout', ['user' => (string) $user, 'seconds' => $seconds])['until'] ?? 0);
    }

    /** Lifts a timeout. Whether there was one. */
    public function untimeout(int|string $user): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/untimeout', ['user' => (string) $user])['lifted'] ?? false);
    }

    /** Out of the stream now, free to come back. Throws `host` for a host. */
    public function kick(int|string $user): void
    {
        $this->realtime->request('POST', $this->path.'/kick', ['user' => (string) $user]);
    }

    /** Out of the stream until it is lifted. Throws `host` for a host. */
    public function ban(int|string $user): void
    {
        $this->realtime->request('POST', $this->path.'/ban', ['user' => (string) $user]);
    }

    /** Lifts a ban. Whether there was one. */
    public function unban(int|string $user): bool
    {
        return (bool) ($this->realtime->request('POST', $this->path.'/unban', ['user' => (string) $user])['lifted'] ?? false);
    }

    /**
     * Everyone banned, the latest first.
     *
     * @return list<array{user: ?string, name: string, avatar: ?string, at: ?int}>
     */
    public function bans(): array
    {
        return array_values((array) ($this->realtime->request('GET', $this->path.'/bans')['bans'] ?? []));
    }

    /** Turns reactions on or off for everyone watching. */
    public function reactions(bool $on): void
    {
        $this->realtime->request('POST', $this->path.'/reactions', ['on' => $on]);
    }

    /**
     * Ends it, as End does for its host.
     *
     * @return array{name: string, state: string, started_at: ?int, ended_at: ?int}
     */
    public function end(): array
    {
        return (array) ($this->realtime->request('POST', $this->path.'/end')['stream'] ?? []);
    }
}
