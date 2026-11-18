<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/**
 * Recordings of the app's group calls and live streams, made by WordPlus Cloud with their transcripts
 * (`/wordplus/realtime/recordings/*`). The files wait in WordPlus Cloud's landing bucket, linked from each recording,
 * seven days at most: take them where the app keeps its files, then acknowledge them with sync().
 */
final class RecordingQueries
{
    private const PATH = '/wordplus/realtime/recordings/';

    public function __construct(private readonly Realtime $realtime) {}

    /**
     * Starts recording a group call (`call`, its name) or a live stream (`stream`). `meta` comes back with it, 1 KB at
     * most, `by` fills it with who started it, and `settings` change how it looks (the realtime server's defaults where
     * left out).
     *
     * @param array{call?: string, stream?: string, type?: 'video'|'audio', by?: int|string, meta?: string, settings?: array<string, mixed>} $what
     * @return array{id: string, state: string}
     */
    public function start(array $what): array
    {
        $body = isset($what['stream'])
            ? ['product' => 'stream', 'stream' => $what['stream']]
            : ['product' => 'call', 'room' => $what['call'] ?? null, 'type' => $what['type'] ?? 'video'];
        $body['meta'] = $what['meta'] ?? (isset($what['by']) ? (string) json_encode(['by' => (string) $what['by']]) : '');

        if (isset($what['settings'])) {
            $body['settings'] = $what['settings'];
        }

        return (array) ($this->realtime->request('POST', self::PATH.'start', $body)['recording'] ?? []);
    }

    /** Stops one: its files are ready about half a minute later. Its state, `stopping`. */
    public function stop(string $id): string
    {
        return (string) ($this->realtime->request('POST', self::PATH.'stop', ['recording' => $id])['state'] ?? '');
    }

    /**
     * One recording now: its `state` (`recording`, `stopping`, `finishing`, `complete`, `failed` with a `reason`), its
     * `seconds`, and once complete its `files`, each linked for a day.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return (array) ($this->realtime->request('POST', self::PATH.'status', ['recording' => $id])['recording'] ?? []);
    }

    /**
     * The recordings finished since the last acknowledged, each until `$ack` names it.
     *
     * @param list<string> $ack
     * @return list<array<string, mixed>>
     */
    public function sync(array $ack = [], int $limit = 20): array
    {
        return array_values((array) ($this->realtime->request('POST', self::PATH.'sync', ['ack' => array_values($ack), 'limit' => $limit])['recordings'] ?? []));
    }
}
