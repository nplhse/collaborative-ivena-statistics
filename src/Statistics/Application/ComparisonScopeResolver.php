<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\Infrastructure\Query\AllocationStatsProjectionScopeQuery;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class ComparisonScopeResolver
{
    public function __construct(
        private StatisticsFilterFactory $statisticsFilterFactory,
        private ComparisonFilterInputFactory $comparisonFilterInputFactory,
        private HospitalAccessInterface $hospitalAccess,
        private HospitalCohortResolver $hospitalCohortResolver,
        private AllocationStatsProjectionScopeQuery $projectionScopeQuery,
    ) {
    }

    public function resolve(
        Request $request,
        ?User $user,
        StatisticsFilter $primaryFilter,
        HospitalPermission $hospitalPermission = HospitalPermission::Statistics,
    ): StatisticsFilter {
        $input = $this->comparisonFilterInputFactory->fromQuery(
            $request->query,
            $primaryFilter,
            $this->defaultComparisonCohort($primaryFilter, $user, $hospitalPermission),
        );

        return $this->statisticsFilterFactory->createFromInput($input, $user);
    }

    private function defaultComparisonCohort(
        StatisticsFilter $primaryFilter,
        ?User $user,
        HospitalPermission $hospitalPermission,
    ): string {
        if (StatisticsFilterScope::HospitalCohort === $primaryFilter->scope && $primaryFilter->cohortType instanceof HospitalCohortKey) {
            return $primaryFilter->cohortType->value();
        }

        $hospitalIds = match ($primaryFilter->scope) {
            StatisticsFilterScope::Hospital => null !== $primaryFilter->hospitalId ? [$primaryFilter->hospitalId] : [],
            StatisticsFilterScope::MyHospitals => $this->accessibleHospitalIds($user, $hospitalPermission),
            StatisticsFilterScope::State => null !== $primaryFilter->stateId
                ? $this->projectionScopeQuery->distinctHospitalIdsForState($primaryFilter->stateId)
                : [],
            StatisticsFilterScope::DispatchArea => null !== $primaryFilter->dispatchAreaId
                ? $this->projectionScopeQuery->distinctHospitalIdsForDispatchArea($primaryFilter->dispatchAreaId)
                : [],
            StatisticsFilterScope::HospitalCohort => $primaryFilter->cohortType instanceof HospitalCohortKey
                ? $this->projectionScopeQuery->distinctHospitalIdsForCohort(
                    $this->hospitalCohortResolver->resolve($primaryFilter->cohortType),
                )
                : [],
            default => [],
        };

        if ([] === $hospitalIds) {
            return HospitalCohortKey::all()[0]->value();
        }

        $dominant = $this->projectionScopeQuery->dominantLocationTierForHospitalIds($hospitalIds);
        if (!\is_array($dominant)) {
            return HospitalCohortKey::all()[0]->value();
        }

        $locationCode = AllocationStatsHospitalLocationProjectionCode::tryFrom($dominant['location']);
        $tierCode = AllocationStatsHospitalTierProjectionCode::tryFrom($dominant['tier']);
        if (!$locationCode instanceof AllocationStatsHospitalLocationProjectionCode
            || !$tierCode instanceof AllocationStatsHospitalTierProjectionCode) {
            return HospitalCohortKey::all()[0]->value();
        }

        return new HospitalCohortKey(
            $locationCode->toHospitalLocation(),
            $tierCode->toHospitalTier(),
        )->value();
    }

    /**
     * @return list<int>
     */
    private function accessibleHospitalIds(?User $user, HospitalPermission $hospitalPermission): array
    {
        if (!$user instanceof User) {
            return [];
        }

        if (HospitalPermission::Benchmarking === $hospitalPermission) {
            if (!$this->hospitalAccess->canUseBenchmarkingScope($user)) {
                return [];
            }

            return $this->hospitalAccess->benchmarkingHospitalIds($user);
        }

        if (!$this->hospitalAccess->canUseMyHospitalsScope($user)) {
            return [];
        }

        return $this->hospitalAccess->accessibleHospitalIds($user);
    }
}
