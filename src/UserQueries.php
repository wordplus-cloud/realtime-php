<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** A user as the realtime server knows them. */
final class UserQueries
{
    private readonly string $path;

    public function __construct(
        private readonly Realtime $realtime,
        public readonly string $id,
    ) {
        $this->path = '/wordplus/realtime/users/'.rawurlencode($id);
    }

    /**
     * Online, or offline since `at`, and how many connections vouched for them are open.
     *
     * @return array{id: string, online: bool, at?: int, connections: int}
     */
    public function get(): array
    {
        return (array) ($this->realtime->request('GET', $this->path)['user'] ?? []);
    }

    /**
     * Takes the user out of everything at once: every room, their identity, their lines, group calls and live streams (a
     * host stays). Their pages then ask your routes again, and the tokens signed before are refused, so your rules decide
     * whether they come back: a user you signed out or blocked does not.
     */
    public function disconnect(): void
    {
        $this->realtime->request('POST', $this->path.'/disconnect');
    }

    /**
     * Asks the pages of the user and waits for their replies: the first by default, every reply within `timeout` (ms, 1,000 to
     * 60,000, 10,000 by default) with `all`. A page answers with `rt.answer( event, handler )` in the browser. `$data` is
     * sealed with the key of the user unless `seal` is false, and the replies are opened. No page answering is `[]`.
     *
     * @param array{timeout?: int, all?: bool, seal?: bool} $options
     *
     * @return list<array{data: mixed, at: int}>
     */
    public function ask(string $event, mixed $data = null, array $options = []): array
    {
        $body = ['event' => $event, 'data' => $this->realtime->sealedFor($this->id, $data, false !== ($options['seal'] ?? true))];

        if (isset($options['timeout'])) {
            $body['timeout'] = (int) $options['timeout'];
        }
        if (!empty($options['all'])) {
            $body['all'] = true;
        }

        $answer = $this->realtime->request('POST', $this->path.'/ask', $body);

        return array_map(fn (array $r) => ['data' => $this->realtime->opened($r['data'] ?? null), 'at' => (int) ($r['at'] ?? 0)], array_values(array_filter((array) ($answer['replies'] ?? []), 'is_array')));
    }
}
