<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** A refusal from the realtime server, or a request that didn't reach it: `code` says which. */
final class RealtimeError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 0,
    ) {
        parent::__construct('The realtime server refused the request: '.$errorCode, $status);
    }
}
