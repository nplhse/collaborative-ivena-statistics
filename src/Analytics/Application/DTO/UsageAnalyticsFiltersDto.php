<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class UsageAnalyticsFiltersDto
{
    /**
     * @param list<array{paramName: string, usageCount: int}>                                                    $topFilterParams
     * @param list<array{featureArea: string, withFilters: int, withoutFilters: int, withFiltersPercent: float}> $filterUsageByArea
     */
    public function __construct(
        public array $topFilterParams,
        public array $filterUsageByArea,
    ) {
    }
}
