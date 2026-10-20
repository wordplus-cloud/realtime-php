<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/**
 * Where an app keeps its keys' generations (`ch:{channel}` and `u:{id}`), so moving a key on holds across its servers:
 * a database row, a cache that doesn't evict, Laravel's cache store (Laravel\CacheGenerations).
 */
interface GenerationStore
{
    public function get(string $name): ?int;

    public function set(string $name, int $generation): void;
}
