<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\HospitalSummaryData;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;

final readonly class HospitalSummaryQuery
{
    public function __construct(
        private StatisticsScopeResolver $scopeResolver,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
    ) {
    }

    public function summarize(StatisticsContext $context): HospitalSummaryData
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $from = $bounds->from;
        $to = $bounds->toExclusive;

        $totalAllocations = $this->timeSeriesQuery->countCreatedInPeriod($from, $to, null);

        $scope = $context->filter->scope;

        if (StatisticsFilterScope::Public === $scope) {
            return $this->buildDataForPublicSlice($totalAllocations, $from, $to);
        }

        if (StatisticsFilterScope::Hospital === $scope && null !== $context->filter->hospitalId) {
            $ids = [$context->filter->hospitalId];

            return $this->buildDataForHospitalIds(
                $totalAllocations,
                $from,
                $to,
                $ids,
                usedUnscopedFallback: false,
            );
        }

        $hospitalIds = $this->scopeResolver->resolveCriteria($context)->hospitalIds;
        if (null === $hospitalIds) {
            return $this->buildDataForPublicSlice($totalAllocations, $from, $to, usedUnscopedFallback: true);
        }

        return $this->buildDataForHospitalIds(
            $totalAllocations,
            $from,
            $to,
            $hospitalIds,
            usedUnscopedFallback: false,
        );
    }

    /**
     * @param list<int> $ids
     */
    private function buildDataForHospitalIds(
        int $totalAllocations,
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        array $ids,
        bool $usedUnscopedFallback,
    ): HospitalSummaryData {
        $userAllocations = $this->timeSeriesQuery->countCreatedInPeriod($from, $to, $ids);
        $genderRaw = $this->timeSeriesQuery->countGroupedByGenderInPeriod($from, $to, $ids);
        $urgencyRaw = $this->timeSeriesQuery->countGroupedByUrgencyInPeriod($from, $to, $ids);

        return $this->assembleHospitalSummaryData(
            $totalAllocations,
            $userAllocations,
            $genderRaw,
            $urgencyRaw,
            $usedUnscopedFallback,
        );
    }

    private function buildDataForPublicSlice(
        int $totalAllocations,
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        bool $usedUnscopedFallback = false,
    ): HospitalSummaryData {
        $genderRaw = $this->timeSeriesQuery->countGroupedByGenderInPeriod($from, $to, null);
        $urgencyRaw = $this->timeSeriesQuery->countGroupedByUrgencyInPeriod($from, $to, null);

        return $this->assembleHospitalSummaryData(
            $totalAllocations,
            $totalAllocations,
            $genderRaw,
            $urgencyRaw,
            $usedUnscopedFallback,
        );
    }

    /**
     * @param array<string, int> $genderRaw
     * @param array<int, int>    $urgencyRaw
     */
    private function assembleHospitalSummaryData(
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
