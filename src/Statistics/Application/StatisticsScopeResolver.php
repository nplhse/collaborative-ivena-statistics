<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Infrastructure\Query\Overview\GetDistinctHospitalIdsByCohortQuery;
use App\Statistics\Infrastructure\Query\Overview\GetDistinctHospitalIdsByDispatchAreaQuery;
use App\Statistics\Infrastructure\Query\Overview\GetDistinctHospitalIdsByStateQuery;
use App\User\Domain\Entity\User;

final class StatisticsScopeResolver
{
    private ?string $resolvedCacheKey = null;

    private ?StatisticsScopeCriteria $resolvedCriteria = null;

    public function __construct(
        private readonly HospitalAccessInterface $hospitalAccess,
        private readonly HospitalCohortResolver $hospitalCohortResolver,
        private readonly GetDistinctHospitalIdsByStateQuery $distinctHospitalIdsByStateQuery,
        private readonly GetDistinctHospitalIdsByDispatchAreaQuery $distinctHospitalIdsByDispatchAreaQuery,
        private readonly GetDistinctHospitalIdsByCohortQuery $distinctHospitalIdsByCohortQuery,
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
        $cacheKey = $this->cacheKey($context);
        if ($cacheKey === $this->resolvedCacheKey && $this->resolvedCriteria instanceof StatisticsScopeCriteria) {
            return $this->resolvedCriteria;
        }

        $filter = $context->filter;

        if (StatisticsFilterScope::Public === $filter->scope) {
            $criteria = StatisticsScopeCriteria::public();
        } elseif (StatisticsFilterScope::Hospital === $filter->scope && null !== $filter->hospitalId) {
            $criteria = new StatisticsScopeCriteria([$filter->hospitalId]);
        } elseif (StatisticsFilterScope::State === $filter->scope && null !== $filter->stateId) {
            $hospitalIds = ($this->distinctHospitalIdsByStateQuery)($filter->stateId);

            $criteria = new StatisticsScopeCriteria([] === $hospitalIds ? null : $hospitalIds);
        } elseif (StatisticsFilterScope::DispatchArea === $filter->scope && null !== $filter->dispatchAreaId) {
            $hospitalIds = ($this->distinctHospitalIdsByDispatchAreaQuery)($filter->dispatchAreaId);

            $criteria = new StatisticsScopeCriteria([] === $hospitalIds ? null : $hospitalIds);
        } elseif (StatisticsFilterScope::HospitalCohort === $filter->scope && $filter->cohortType instanceof Cohort\HospitalCohortKey) {
            $cohort = $this->hospitalCohortResolver->resolve($filter->cohortType);
            $hospitalIds = ($this->distinctHospitalIdsByCohortQuery)($cohort);

            $criteria = new StatisticsScopeCriteria(
                [] === $hospitalIds ? null : $hospitalIds,
                $cohort->locationCodeValues(),
                $cohort->tierCodeValues(),
                $filter->cohortType,
            );
        } else {
            $ids = $this->resolveMyHospitalIds($context->user);

            $criteria = [] === $ids ? StatisticsScopeCriteria::public() : new StatisticsScopeCriteria($ids);
        }

        $this->resolvedCacheKey = $cacheKey;
        $this->resolvedCriteria = $criteria;

        return $criteria;
    }

    private function cacheKey(StatisticsContext $context): string
    {
        $filter = $context->filter;
        $userId = $context->user?->getId();

        return implode('|', [
            $filter->scope->value,
            (string) ($filter->hospitalId ?? ''),
            $filter->cohortType instanceof Cohort\HospitalCohortKey ? $filter->cohortType->value() : '',
            (string) ($filter->stateId ?? ''),
            (string) ($filter->dispatchAreaId ?? ''),
            null === $userId ? 'anon' : (string) $userId,
        ]);
    }

    /**
     * @return list<int>
     */
    private function resolveMyHospitalIds(?User $user): array
    {
        if (!$user instanceof User || !$this->hospitalAccess->canUseMyHospitalsScope($user)) {
            return [];
        }

        return $this->hospitalAccess->accessibleHospitalIds($user);
    }
}
