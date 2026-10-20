<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** Where an emit goes: rooms and users, and a tab that doesn't get it back. `emit()` sends it. */
final class Emitter
{
    /** @var array<string, true> */
    private array $rooms = [];

    /** @var array<string, true> */
    private array $users = [];

    private ?string $skip = null;

    public function __construct(private readonly Realtime $realtime) {}

    /** @param string|list<string> $rooms */
    public function to(string|array $rooms): self
    {
        foreach ((array) $rooms as $room) {
            $this->rooms[(string) $room] = true;
        }

        return $this;
    }

    /** @param int|string|list<int|string> $users */
    public function toUser(int|string|array $users): self
    {
        foreach ((array) $users as $user) {
            if (null !== $user && '' !== $user && 0 !== $user && '0' !== $user) {
                $this->users[(string) $user] = true;
            }
        }

        return $this;
    }

    /** The tab whose action made the event (the browser's `rt.tab`, sent as X-Realtime-Tab), which doesn't get it back. */
    public function except(?string $tab): self
    {
        if (null !== $tab && '' !== $tab) {
            $this->skip = $tab;
        }

        return $this;
    }

    /**
     * Sends the event: a sealed room's data sealed with its key, a user's with theirs, kept where a room's rule keeps
     * history. The kept events' ids, by room.
     *
     * @param array{seal?: bool} $options
     *
     * @return array<string, string>
     *
     * @throws RealtimeError the server's reason
     */
    public function emit(string $event, mixed $data = null, array $options = []): array
    {
        return $this->realtime->emitEvent(['rooms' => array_map('strval', array_keys($this->rooms)), 'users' => array_map('strval', array_keys($this->users)), 'event' => $event, 'data' => $data, 'except' => $this->skip] + $options);
    }
}
