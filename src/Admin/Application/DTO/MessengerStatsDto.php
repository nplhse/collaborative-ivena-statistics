<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

final readonly class MessengerStatsDto
{
    /**
     * @param list<MessengerQueueStatDto> $queues
     */
    public function __construct(
        public array $queues,
        public int $failedCount,
    ) {
    }

    public function pendingCountFor(string $queueName): int
    {
        foreach ($this->queues as $queue) {
            if ($queue->queueName === $queueName) {
                return $queue->pendingCount;
            }
        }

        return 0;
    }
}
