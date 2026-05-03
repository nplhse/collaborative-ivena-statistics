<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Entity\User;

/**
 * Most frequent diagnosis / indication labels (normalized, otherwise raw name) for the selected period and scope.
 *
 * @phpstan-type Row array{label: string, count: int}
 */
final readonly class TopDiagnosesQuery
{
    public function __construct(
        private AllocationRepository $allocationRepository,
        private HospitalRepository $hospitalRepository,
    ) {
    }

    /**
     * @return array{rows: list<Row>, totalAllocations: int}
     */
    public function fetch(StatisticsContext $context, int $limit): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $hospitalIds = $this->hospitalIdsOrNull($context);

        $rows = $this->allocationRepository->fetchTopDiagnosisAggregates(
            $bounds->from,
            $bounds->toExclusive,
            $hospitalIds,
            $limit,
        );

        $total = null === $hospitalIds
            ? $this->allocationRepository->countCreatedInPeriod($bounds->from, $bounds->toExclusive)
            : $this->allocationRepository->countCreatedInPeriodForHospitals(
                $bounds->from,
                $bounds->toExclusive,
                $hospitalIds,
            );

        return [
            'rows' => $rows,
            'totalAllocations' => $total,
        ];
    }

    /**
     * null = public aggregation without hospital IN filter; non-empty list = IN clause.
     *
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
