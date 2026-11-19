<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

use App\Shared\Application\Health\HealthCheckStatus;

/**
 * Compact Messenger state for the admin dashboard card. The status is the worst of the Messenger health checks,
 * so the card and the health banner never disagree; the queue numbers follow the Messenger page.
 */
final readonly class MessengerStatusDto
{
    /**
     * @param array<string, int> $pendingByQueue messages ready to be handled, per queue (failure queue excluded)
     */
    public function __construct(
        public HealthCheckStatus $status,
        public array $pendingByQueue,
        public int $failedCount,
        public ?\DateTimeImmutable $oldestPendingAt,
        public string $detailUrl,
        public string $failedMessagesUrl,
    ) {
    }

    public function pendingTotal(): int
    {
        return array_sum($this->pendingByQueue);
    }
}
