<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

final readonly class MessengerQueueStatDto
{
    public function __construct(
        public string $queueName,
        public int $pendingCount,
        public ?\DateTimeImmutable $oldestCreatedAt,
    ) {
    }
}
