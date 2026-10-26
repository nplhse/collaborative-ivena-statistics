<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO\Messenger;

/**
 * Summed counters of messenger_metric (ADR 019). handled + failed are final outcomes of logical messages;
 * attempts and retried count handling attempts.
 */
final readonly class MessengerCountsDto
{
    public function __construct(
        public int $attempts = 0,
        public int $handled = 0,
        public int $handledAfterRetry = 0,
        public int $retried = 0,
        public int $failed = 0,
        public int $durationMsSum = 0,
        public int $durationMsMax = 0,
        public ?\DateTimeImmutable $lastActivityAt = null,
    ) {
    }

    public function add(self $other): self
    {
        return new self(
            $this->attempts + $other->attempts,
            $this->handled + $other->handled,
            $this->handledAfterRetry + $other->handledAfterRetry,
            $this->retried + $other->retried,
            $this->failed + $other->failed,
            $this->durationMsSum + $other->durationMsSum,
            max($this->durationMsMax, $other->durationMsMax),
            !$this->lastActivityAt instanceof \DateTimeImmutable || ($other->lastActivityAt instanceof \DateTimeImmutable && $other->lastActivityAt > $this->lastActivityAt)
                ? $other->lastActivityAt
                : $this->lastActivityAt,
        );
    }

    /** Share of logical messages that ended handled, in percent; null without any final outcome. */
    public function successRate(): ?float
    {
        $outcomes = $this->handled + $this->failed;

        return 0 === $outcomes ? null : round($this->handled * 100 / $outcomes, 1);
    }

    /** Share of attempts that failed and were retried, in percent; null without attempts. */
    public function retryRate(): ?float
    {
        return 0 === $this->attempts ? null : round($this->retried * 100 / $this->attempts, 1);
    }

    public function averageDurationMs(): ?int
    {
        return 0 === $this->attempts ? null : (int) round($this->durationMsSum / $this->attempts);
    }
}
