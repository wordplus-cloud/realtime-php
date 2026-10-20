<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** Who is online across the app, from the realtime server. */
final class OnlineQueries
{
    public function __construct(private readonly Realtime $realtime) {}

    /**
     * Each user's status: online, or offline since when they were last seen.
     *
     * @param list<int|string> $users
     *
     * @return list<array{id: string, online: bool, at?: int}>
     */
    public function states(array $users): array
    {
        if ([] === $users) {
            return [];
        }

        $ids = implode(',', array_map(static fn ($u) => rawurlencode((string) $u), $users));

        return array_values((array) ($this->realtime->request('GET', '/wordplus/realtime/online?ids='.$ids)['online'] ?? []));
    }

    public function has(int|string $user): bool
    {
        return (bool) ($this->states([$user])[0]['online'] ?? false);
    }

    public function count(): int
    {
        return (int) ($this->realtime->request('GET', '/wordplus/realtime/online?limit=1')['count'] ?? 0);
    }

    /**
     * A page of who is online: their ids, and the cursor of the next page ('0' after the last).
     *
     * @return array{ids: list<string>, next: string}
     */
    public function list(string $cursor = '0', int $limit = 1000): array
    {
        $answer = $this->realtime->request('GET', '/wordplus/realtime/online?cursor='.rawurlencode($cursor).'&limit='.$limit);

        return ['ids' => array_values(array_map('strval', (array) ($answer['ids'] ?? []))), 'next' => (string) ($answer['next'] ?? '0')];
    }
}
