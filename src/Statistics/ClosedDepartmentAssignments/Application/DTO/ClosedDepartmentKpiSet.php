<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

final readonly class ClosedDepartmentKpiSet
{
    public function __construct(
        public int $closedCount,
        public int $totalCount,
        public ?float $sharePercent,
        public int $departmentCount,
        public int $totalDepartmentCount,
        public ?float $meanTransportMinutes,
    ) {
    }
}
