<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\HospitalSummaryData;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;

final readonly class HospitalSummaryQuery
{
    public function __construct(
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    public function summarize(StatisticsContext $context, OverviewDashboardMetricsResult $metrics): HospitalSummaryData
    {
        $scope = $context->filter->scope;
        $usedUnscopedFallback = StatisticsFilterScope::Public !== $scope
            && null === $this->scopeResolver->resolveCriteria($context)->hospitalIds;

        return $this->buildDataFromDistributions(
            $metrics->platformTotal,
            $metrics->scopedTotal,
            $metrics->genderCounts,
            $metrics->urgencyCounts,
            $usedUnscopedFallback,
        );
    }

    /**
     * @param array<string, int> $genderRaw
     * @param array<int, int>    $urgencyRaw
     */
    private function buildDataFromDistributions(
        int $totalAllocations,
        int $userAllocations,
        array $genderRaw,
        array $urgencyRaw,
        bool $usedUnscopedFallback,
    ): HospitalSummaryData {
        $genderCounts = [];
        foreach (AllocationGender::cases() as $case) {
            $genderCounts[$case->value] = $genderRaw[$case->value] ?? 0;
        }

        $urgencyCounts = [
            AllocationUrgency::EMERGENCY->value => $urgencyRaw[AllocationUrgency::EMERGENCY->value] ?? 0,
            AllocationUrgency::INPATIENT->value => $urgencyRaw[AllocationUrgency::INPATIENT->value] ?? 0,
            AllocationUrgency::OUTPATIENT->value => $urgencyRaw[AllocationUrgency::OUTPATIENT->value] ?? 0,
        ];

        return new HospitalSummaryData(
            $totalAllocations,
            $userAllocations,
            $genderCounts,
            $urgencyCounts,
            $usedUnscopedFallback,
        );
    }
}
