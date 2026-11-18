<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** A room as the realtime server holds it. */
final class RoomQueries
{
    public function __construct(
        private readonly Realtime $realtime,
        public readonly string $room,
    ) {}

    /** How many are in it: a presence room's members, else its connections. */
    public function count(): int
    {
        return (int) ($this->realtime->request('GET', '/wordplus/realtime/rooms/'.rawurlencode($this->room))['count'] ?? 0);
    }

    /**
     * A presence room's members, their info opened: the first 1,000, the count, and `next` to page on.
     *
     * @return array{count: int, list: list<array{id: string, info: mixed}>, next?: string}
     */
    public function members(): array
    {
        $answer = $this->realtime->request('GET', '/wordplus/realtime/rooms/'.rawurlencode($this->room).'?members=1');
        $members = is_array($answer['members'] ?? null) ? $answer['members'] : ['count' => 0, 'list' => []];
        $members['count'] = (int) ($members['count'] ?? 0);
        $members['list'] = array_map(fn (array $m) => ['id' => (string) ($m['id'] ?? ''), 'info' => $this->realtime->opened($m['info'] ?? null)], array_values(array_filter((array) ($members['list'] ?? []), 'is_array')));

        return $members;
    }

    /**
     * A page of the room's history, opened, oldest first: the newest `limit` (50), or those before or after an event's
     * id.
     *
     * @param array{before?: string, after?: string, limit?: int, days?: int} $ask
     *
     * @return array{events: list<array{id: string, at: int, event: string, data: mixed, from?: string}>, more: bool}
     */
    public function history(array $ask = []): array
    {
        $query = http_build_query(array_filter($ask, static fn ($v) => null !== $v));
        $answer = $this->realtime->request('GET', '/wordplus/realtime/rooms/'.rawurlencode($this->room).'/history'.('' === $query ? '' : '?'.$query));
        $events = [];

        foreach (array_filter((array) ($answer['events'] ?? []), 'is_array') as $e) {
            $event = ['id' => (string) ($e['id'] ?? ''), 'at' => (int) ($e['at'] ?? 0), 'event' => (string) ($e['event'] ?? ''), 'data' => $this->realtime->opened($e['data'] ?? null)];

            if (isset($e['from'])) {
                $event['from'] = (string) $e['from'];
            }

            $events[] = $event;
        }

        return ['events' => $events, 'more' => !empty($answer['more'])];
    }

    /** Erases the room's history: one event by its id, or all of it. How many were erased. */
    public function forget(?string $id = null): int
    {
        $path = '/wordplus/realtime/rooms/'.rawurlencode($this->room).'/history'.(null === $id ? '' : '?id='.rawurlencode($id));

        return (int) ($this->realtime->request('DELETE', $path)['deleted'] ?? 0);
    }

    /**
     * Sends to the room, as `to( $room )->emit()` does: the kept event ids, by room.
     *
     * @param array{seal?: bool} $options
     * @return array<string, string>
     */
    public function emit(string $event, mixed $data = null, array $options = []): array
    {
        return $this->realtime->to($this->room)->emit($event, $data, $options);
    }

    /**
     * Takes a user out of the room at once, refuses their tokens signed before, and moves the room's key on when keys have
     * generations. A reload asks the room's rule again, so to keep them out, the rule says no for them too.
     */
    public function remove(int|string $user): void
    {
        $this->about('kick', ['user' => (string) $user]);
    }

    /**
     * Takes everyone out of the room at once, on every server, each page hearing `kicked`, as `remove()` does for one. Those
     * your rule still lets in can join again, so to close the room, have the rule say no first. A public room takes anyone
     * back, as it always does.
     */
    public function removeEveryone(): void
    {
        $this->about('kick', ['everyone' => true]);
    }

    /**
     * Asks the room's rule again for a user, in this room alone: their pages leave it and ask your server again by themselves,
     * and come back with what the rule answers now, or hear why not. For a role that changed, such as a member made a reader.
     * The others in the room see them leave and join again. Their other rooms and calls are left alone.
     */
    public function recheck(int|string $user): void
    {
        $this->about('recheck', ['user' => (string) $user]);
    }

    /** `recheck()` for everyone in the room, for a rule whose answer changed for all of them. */
    public function recheckEveryone(): void
    {
        $this->about('recheck', ['everyone' => true]);
    }

    /** @param array<string, mixed> $who */
    private function about(string $action, array $who): void
    {
        $this->realtime->request('POST', '/wordplus/realtime/rooms/'.rawurlencode($this->room).'/'.$action, $who);

        try {
            $this->realtime->rotateRoom($this->room);
        } catch (\LogicException) {
            // No generation store: it holds, and what the room carries next is still sealed with the key they had.
        }
    }

    /** @deprecated `remove()`. */
    public function kick(int|string $user): void
    {
        $this->remove($user);
    }
}
