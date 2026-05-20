<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Cohort\HospitalCohortType;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
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

    public function resolve(Request $request, ?User $user, StatisticsFilter $primaryFilter): StatisticsFilter
    {
        $input = $this->comparisonFilterInputFactory->fromQuery(
            $request->query,
            $primaryFilter,
            $this->defaultComparisonCohort($primaryFilter, $user),
        );

        return $this->statisticsFilterFactory->createFromInput($input, $user);
    }

    private function defaultComparisonCohort(StatisticsFilter $primaryFilter, ?User $user): string
    {
        if (StatisticsFilterScope::HospitalCohort === $primaryFilter->scope && $primaryFilter->cohortType instanceof HospitalCohortType) {
            return $primaryFilter->cohortType->value;
        }

        $hospitalIds = match ($primaryFilter->scope) {
            StatisticsFilterScope::Hospital => null !== $primaryFilter->hospitalId ? [$primaryFilter->hospitalId] : [],
            StatisticsFilterScope::MyHospitals => $this->accessibleHospitalIds($user),
            StatisticsFilterScope::State => null !== $primaryFilter->stateId
                ? $this->projectionScopeQuery->distinctHospitalIdsForState($primaryFilter->stateId)
                : [],
            StatisticsFilterScope::DispatchArea => null !== $primaryFilter->dispatchAreaId
                ? $this->projectionScopeQuery->distinctHospitalIdsForDispatchArea($primaryFilter->dispatchAreaId)
                : [],
            StatisticsFilterScope::HospitalCohort => $primaryFilter->cohortType instanceof HospitalCohortType
                ? $this->projectionScopeQuery->distinctHospitalIdsForCohort(
                    $this->hospitalCohortResolver->resolve($primaryFilter->cohortType),
                )
                : [],
            default => [],
        };

        if ([] === $hospitalIds) {
            return HospitalCohortType::cases()[0]->value;
        }

        $dominant = $this->projectionScopeQuery->dominantLocationTierForHospitalIds($hospitalIds);
        if (!\is_array($dominant)) {
            return HospitalCohortType::cases()[0]->value;
        }

        foreach (HospitalCohortType::cases() as $candidate) {
            $cohort = $this->hospitalCohortResolver->resolve($candidate);
            if (\in_array($dominant['location'], $cohort->locationCodeValues(), true) && \in_array($dominant['tier'], $cohort->tierCodeValues(), true)) {
                return $candidate->value;
            }
        }

        return HospitalCohortType::cases()[0]->value;
    }

    /**
     * @return list<int>
     */
    private function accessibleHospitalIds(?User $user): array
    {
        if (!$user instanceof User || !$this->hospitalAccess->canUseMyHospitalsScope($user)) {
            return [];
        }

        return $this->hospitalAccess->accessibleHospitalIds($user);
    }
}
