<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO\Messenger;

/**
 * One configured transport: its live queue state (null where the transport has no queue, e.g. the scheduler)
 * and its handling counters in the selected period.
 */
final readonly class MessengerTransportRowDto
{
    public function __construct(
        public string $name,
        public ?string $queueName,
        public ?int $pending,
        public ?int $waitingForRetry,
        public ?\DateTimeImmutable $oldestPendingAt,
        public MessengerCountsDto $counts,
    ) {
    }

    public function hasQueue(): bool
    {
        return null !== $this->queueName;
    }
}
