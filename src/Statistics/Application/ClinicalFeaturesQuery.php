<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Entity\User;

final readonly class ClinicalFeaturesQuery
{
    /** @var list<array{key: string, labelTranslationKey: string}> */
    private const array CLINICAL_FEATURE_DEFINITIONS = [
        ['key' => 'with_physician', 'labelTranslationKey' => 'statistics.distribution.dim.is_with_physician'],
        ['key' => 'cpr', 'labelTranslationKey' => 'statistics.distribution.dim.is_cpr'],
        ['key' => 'ventilated', 'labelTranslationKey' => 'statistics.distribution.dim.is_ventilated'],
        ['key' => 'shock', 'labelTranslationKey' => 'stats.analysis.feature.is_shock'],
        ['key' => 'pregnant', 'labelTranslationKey' => 'stats.analysis.feature.is_pregnant'],
        ['key' => 'infectious', 'labelTranslationKey' => 'field.infection'],
    ];

    /** @var list<array{key: string, labelTranslationKey: string}> */
    private const array RESOURCE_FEATURE_DEFINITIONS = [
        ['key' => 'cathlab_required', 'labelTranslationKey' => 'statistics.distribution.dim.requires_cathlab'],
        ['key' => 'resus_required', 'labelTranslationKey' => 'statistics.distribution.dim.requires_resus'],
    ];

    public function __construct(
        private AllocationRepository $allocationRepository,
        private HospitalRepository $hospitalRepository,
    ) {
    }

    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchClinicalRows(StatisticsContext $context): array
    {
        [$totalAllocations, $clinicalCounts] = $this->loadCounts($context);

        $rows = [];
        foreach (self::CLINICAL_FEATURE_DEFINITIONS as $definition) {
            $count = $clinicalCounts[$definition['key']] ?? 0;
            $rows[] = [
                'labelTranslationKey' => $definition['labelTranslationKey'],
                'count' => $count,
                'percent' => $totalAllocations > 0 ? round(($count / $totalAllocations) * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{labelTranslationKey: string, count: int, percent: float}>
     */
    public function fetchResourceRows(StatisticsContext $context): array
    {
        [$totalAllocations, , $resourceCounts] = $this->loadCounts($context);

        $rows = [];
        foreach (self::RESOURCE_FEATURE_DEFINITIONS as $definition) {
            $count = match ($definition['key']) {
                'cathlab_required' => $resourceCounts['cathlab'] ?? 0,
                'resus_required' => $resourceCounts['resus'] ?? 0,
                default => 0,
            };
            $rows[] = [
                'labelTranslationKey' => $definition['labelTranslationKey'],
                'count' => $count,
                'percent' => $totalAllocations > 0 ? round(($count / $totalAllocations) * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0:int,1:array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,infectious:int},2:array{cathlab:int,resus:int}}
     */
    private function loadCounts(StatisticsContext $context): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $hospitalIds = $this->hospitalIdsOrNull($context);

        $totalAllocations = null === $hospitalIds
            ? $this->allocationRepository->countCreatedInPeriod($bounds->from, $bounds->toExclusive)
            : $this->allocationRepository->countCreatedInPeriodForHospitals($bounds->from, $bounds->toExclusive, $hospitalIds);

        $clinicalCounts = $this->fetchClinicalFeatureCounts($context, $hospitalIds, $bounds->from, $bounds->toExclusive);
        $resourceCounts = $this->fetchResourceFeatureCounts($context, $hospitalIds, $bounds->from, $bounds->toExclusive);

        return [$totalAllocations, $clinicalCounts, $resourceCounts];
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int}
     */
    private function fetchClinicalFeatureCounts(
        StatisticsContext $context,
        ?array $hospitalIds,
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
    ): array {
        $buckets = match ($context->filter->period) {
            StatisticsFilterPeriod::Month => null === $hospitalIds
                ? $this->allocationRepository->bucketAllocationsByDayClinicalFeaturesInRange($from, $toExclusive ?? $from)
                : $this->allocationRepository->bucketAllocationsByDayClinicalFeaturesInRangeForHospitals($from, $toExclusive ?? $from, $hospitalIds),
            default => null === $hospitalIds
                ? $this->allocationRepository->bucketAllocationsByMonthClinicalFeaturesInRange($from, $toExclusive)
                : $this->allocationRepository->bucketAllocationsByMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds),
        };

        $sum = [
            'with_physician' => 0,
            'cpr' => 0,
            'ventilated' => 0,
            'shock' => 0,
            'pregnant' => 0,
            'infectious' => 0,
        ];

        foreach ($buckets as $bucket) {
            $sum['with_physician'] += $bucket['with_physician'] ?? 0;
            $sum['cpr'] += $bucket['cpr'] ?? 0;
            $sum['ventilated'] += $bucket['ventilated'] ?? 0;
            $sum['shock'] += $bucket['shock'] ?? 0;
            $sum['pregnant'] += $bucket['pregnant'] ?? 0;
            $sum['infectious'] += $bucket['infectious'] ?? 0;
        }

        return $sum;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array{cathlab: int, resus: int}
     */
    private function fetchResourceFeatureCounts(
        StatisticsContext $context,
        ?array $hospitalIds,
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
    ): array {
        $buckets = match ($context->filter->period) {
            StatisticsFilterPeriod::Month => null === $hospitalIds
                ? $this->allocationRepository->bucketAllocationsByDayResourcesRequiredInRange($from, $toExclusive ?? $from)
                : $this->allocationRepository->bucketAllocationsByDayResourcesRequiredInRangeForHospitals($from, $toExclusive ?? $from, $hospitalIds),
            default => null === $hospitalIds
                ? $this->allocationRepository->bucketAllocationsByMonthResourcesRequiredInRange($from, $toExclusive)
                : $this->allocationRepository->bucketAllocationsByMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds),
        };

        $sum = ['cathlab' => 0, 'resus' => 0];
        foreach ($buckets as $bucket) {
            $sum['cathlab'] += $bucket['cathlab'] ?? 0;
            $sum['resus'] += $bucket['resus'] ?? 0;
        }

        return $sum;
    }

    /**
     * @return list<int>|null
     */
    private function hospitalIdsOrNull(StatisticsContext $context): ?array
    {
        $filter = $context->filter;

        if (StatisticsFilterScope::Public === $filter->scope) {
            return null;
        }

        if (StatisticsFilterScope::Hospital === $filter->scope && null !== $filter->hospitalId) {
            return [$filter->hospitalId];
        }

        $ids = $this->resolveMyHospitalIds($context->user);
        if ([] === $ids) {
            return null;
        }

        return $ids;
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
