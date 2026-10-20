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

    /** Takes a user out of the room at once, refuses their tokens signed before, and moves the room's key on when keys have generations. */
    public function kick(int|string $user): void
    {
        $this->realtime->request('POST', '/wordplus/realtime/rooms/'.rawurlencode($this->room).'/kick', ['user' => (string) $user]);

        try {
            $this->realtime->rotateRoom($this->room);
        } catch (\LogicException) {
            // No generation store: the kick holds, and what the room carries next is still sealed with the key they had.
        }
    }
}
