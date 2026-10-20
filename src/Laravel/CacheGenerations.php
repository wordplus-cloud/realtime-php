<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Laravel;

use Illuminate\Contracts\Cache\Repository;
use WordPlus\Realtime\GenerationStore;

/** The keys' generations in one of Laravel's cache stores, kept for good: a store that doesn't evict (database, redis). */
final class CacheGenerations implements GenerationStore
{
    public function __construct(private readonly Repository $cache) {}

    public function get(string $name): ?int
    {
        $value = $this->cache->get('wordplus-realtime:generation:'.$name);

        return null === $value ? null : (int) $value;
    }

    public function set(string $name, int $generation): void
    {
        $this->cache->forever('wordplus-realtime:generation:'.$name, $generation);
    }
}
