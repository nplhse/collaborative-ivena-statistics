<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

final readonly class ClosedDepartmentDistributionRow
{
    public function __construct(
        public string $labelTranslationKey,
        public int $count,
        public ?float $percent,
        public ?string $exploreUrl = null,
        public string $barClass = 'bg-primary',
        public ?float $regularPercent = null,
        public ?float $deltaPp = null,
    ) {
    }
}
