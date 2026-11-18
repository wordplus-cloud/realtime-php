<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/** An emit to everyone, and a tab that doesn't get it back. `emit()` sends it. */
final class EveryoneEmitter
{
    private ?string $skip = null;

    public function __construct(private readonly Realtime $realtime) {}

    /** The tab whose action made the event (the browser's `rt.tab`, sent as X-Realtime-Tab), which doesn't get it back. */
    public function except(?string $tab): self
    {
        if (null !== $tab && '' !== $tab) {
            $this->skip = $tab;
        }

        return $this;
    }

    /**
     * Sends the event to every page and app connected now, never sealed or kept.
     *
     * @throws RealtimeError the server's reason
     */
    public function emit(string $event, mixed $data = null): void
    {
        $this->realtime->emitEvent(['everyone' => true, 'event' => $event, 'data' => $data] + (null !== $this->skip ? ['except' => $this->skip] : []));
    }
}
