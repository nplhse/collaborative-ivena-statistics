<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\DTO\HospitalSummaryData;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Entity\User;

final readonly class HospitalSummaryQuery
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private AllocationRepository $allocationRepository,
    ) {
    }

    public function summarize(StatisticsContext $context): HospitalSummaryData
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $from = $bounds->from;
        $to = $bounds->toExclusive;

        $totalAllocations = $this->allocationRepository->countCreatedInPeriod($from, $to);

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

        $hospitalIds = $this->resolveMyHospitalIds($context->user);
        if ([] === $hospitalIds) {
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
        $userAllocations = $this->allocationRepository->countCreatedInPeriodForHospitals($from, $to, $ids);
        $genderRaw = $this->allocationRepository->countGroupedByGenderInPeriodForHospitals($from, $to, $ids);
        $urgencyRaw = $this->allocationRepository->countGroupedByUrgencyInPeriodForHospitals($from, $to, $ids);

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
        $genderRaw = $this->allocationRepository->countGroupedByGenderInPeriod($from, $to);
        $urgencyRaw = $this->allocationRepository->countGroupedByUrgencyInPeriod($from, $to);

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

    /**
     * @return list<int>
     */
    private function resolveMyHospitalIds(?User $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        /** @var list<int|string> $rawIds */
        $rawIds = $this->hospitalRepository
            ->getQueryBuilderForAccessibleHospitals($user)
            ->select('h.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $rawIds);
    }
}
