<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Query\AllocationStatsProjectionScopeQuery;
use App\User\Domain\Entity\User;

final readonly class StatisticsScopeResolver
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private HospitalCohortResolver $hospitalCohortResolver,
        private AllocationStatsProjectionScopeQuery $projectionScopeQuery,
    ) {
    }

    /**
     * null = public aggregation without hospital IN filter, non-empty list = IN clause.
     *
     * Keeps existing semantics: my_hospitals with no accessible ids falls back to unscoped/public.
     *
     * @return list<int>|null
     */
    public function hospitalIdsOrNull(StatisticsContext $context): ?array
    {
        return $this->resolveCriteria($context)->hospitalIds;
    }

    public function resolveCriteria(StatisticsContext $context): StatisticsScopeCriteria
    {
        $filter = $context->filter;

        if (StatisticsFilterScope::Public === $filter->scope) {
            return StatisticsScopeCriteria::public();
        }

        if (StatisticsFilterScope::Hospital === $filter->scope && null !== $filter->hospitalId) {
            return new StatisticsScopeCriteria([$filter->hospitalId]);
        }

        if (StatisticsFilterScope::HospitalCohort === $filter->scope && null !== $filter->cohortType) {
            $cohort = $this->hospitalCohortResolver->resolve($filter->cohortType);
            $hospitalIds = $this->projectionScopeQuery->distinctHospitalIdsForCohort($cohort);

            return new StatisticsScopeCriteria(
                [] === $hospitalIds ? null : $hospitalIds,
                $cohort->locationCodeValues(),
                $cohort->tierCodeValues(),
                $filter->cohortType,
            );
        }

        $ids = $this->resolveMyHospitalIds($context->user);

        return [] === $ids ? StatisticsScopeCriteria::public() : new StatisticsScopeCriteria($ids);
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
