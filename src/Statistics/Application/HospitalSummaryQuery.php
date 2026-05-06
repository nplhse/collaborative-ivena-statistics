<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\HospitalSummaryData;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Repository\AllocationStatsProjectionRepository;

final readonly class HospitalSummaryQuery
{
    public function __construct(
        private StatisticsScopeResolver $scopeResolver,
        private AllocationStatsProjectionRepository $projectionRepository,
    ) {
    }

    public function summarize(StatisticsContext $context): HospitalSummaryData
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $from = $bounds->from;
        $to = $bounds->toExclusive;

        $totalAllocations = $this->projectionRepository->countCreatedInPeriod($from, $to, null);

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

        $hospitalIds = $this->scopeResolver->hospitalIdsOrNull($context);
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
        $userAllocations = $this->projectionRepository->countCreatedInPeriod($from, $to, $ids);
        $genderRaw = $this->projectionRepository->countGroupedByGenderInPeriod($from, $to, $ids);
        $urgencyRaw = $this->projectionRepository->countGroupedByUrgencyInPeriod($from, $to, $ids);

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
        $genderRaw = $this->projectionRepository->countGroupedByGenderInPeriod($from, $to, null);
        $urgencyRaw = $this->projectionRepository->countGroupedByUrgencyInPeriod($from, $to, null);

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
