<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class AnalyticsRetentionResult
{
    /**
     * @param list<string> $datesCleaned
     */
    public function __construct(
        public int $requestsDeleted,
        public int $eventsDeleted,
        public array $datesCleaned,
    ) {
    }

    public function rawRowsDeleted(): int
    {
        return $this->requestsDeleted + $this->eventsDeleted;
    }
}
