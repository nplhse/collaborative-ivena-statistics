<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * Whether a Messenger worker takes the background work of generation runs, read from the queue (#791). A worker
 * marks the message it handles as delivered; messages that wait long with none delivered mean no worker runs.
 */
final readonly class WorkerState
{
    /** Waiting this long with nothing in progress counts as "no worker". */
    public const int NO_WORKER_AFTER_SECONDS = 60;

    public function __construct(
        /** False when the queue is not a database table (another transport): nothing can be said then. */
        public bool $known,
        public int $waiting = 0,
        public int $inProgress = 0,
        public int $oldestWaitingSeconds = 0,
    ) {
    }

    public static function unknown(): self
    {
        return new self(known: false);
    }

    public function isBusy(): bool
    {
        return $this->known && $this->inProgress > 0;
    }

    public function isIdle(): bool
    {
        return $this->known && 0 === $this->inProgress && 0 === $this->waiting;
    }

    public function seemsMissing(): bool
    {
        return $this->known && 0 === $this->inProgress && $this->waiting > 0 && $this->oldestWaitingSeconds >= self::NO_WORKER_AFTER_SECONDS;
    }
}
