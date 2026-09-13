<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

final readonly class ClosedDepartmentNamedRow
{
    public function __construct(
        public ?int $id,
        public string $name,
        public int $closedCount,
        public ?float $shareOfClosed,
        public ?string $exploreUrl,
    ) {
    }
}
