<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** The webhook of the app as the realtime server sends it. */
final class WebhookQueries
{
    public function __construct(private readonly Realtime $realtime) {}

    /**
     * The last 100 tries of the last three days, the newest first: each batch by its `id`, its HTTP `status` (0 for none),
     * `next_at` while it is tried again, and whether it is `resendable`.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveries(): array
    {
        return array_values((array) ($this->realtime->request('GET', '/wordplus/realtime/webhooks')['deliveries'] ?? []));
    }

    /**
     * A batch your server did not take, sent again now. Throws `not_found` once it is gone.
     *
     * @return array{delivered: bool, status: int, error?: string}
     */
    public function resend(string $id): array
    {
        return self::outcome($this->realtime->request('POST', '/wordplus/realtime/webhooks/'.rawurlencode($id).'/resend'));
    }

    /**
     * One `webhook.test` event sent now, to see your webhook take it. Throws `no_webhook` for an app without one.
     *
     * @return array{delivered: bool, status: int, error?: string}
     */
    public function test(): array
    {
        return self::outcome($this->realtime->request('POST', '/wordplus/realtime/webhooks/test'));
    }

    /**
     * @param array<string, mixed> $answer
     *
     * @return array{delivered: bool, status: int, error?: string}
     */
    private static function outcome(array $answer): array
    {
        $out = ['delivered' => (bool) ($answer['delivered'] ?? false), 'status' => (int) ($answer['status'] ?? 0)];

        if (isset($answer['error'])) {
            $out['error'] = (string) $answer['error'];
        }

        return $out;
    }
}
