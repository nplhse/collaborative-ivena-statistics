<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

use App\Shared\Application\Health\HealthCheckStatus;

final readonly class HealthStatusDto
{
    /**
     * @param array<string, string>            $checks
     * @param list<HealthCheckItemDto>         $items
     * @param array<string, HealthCheckStatus> $statuses status of each check
     */
    public function __construct(
        public HealthCheckStatus $status,
        public string $appVersion,
        public array $checks,
        public array $items,
        public array $statuses = [],
    ) {
    }
}
