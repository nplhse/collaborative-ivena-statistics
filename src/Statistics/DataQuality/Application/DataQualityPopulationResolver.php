<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Application;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\DataQuality\Application\Contract\DataQualityHospitalPopulationReaderInterface;
use App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot;
use App\User\Domain\Entity\User;

final readonly class DataQualityPopulationResolver
{
    public function __construct(
        private DataQualityHospitalPopulationReaderInterface $populationQuery,
        private HospitalCohortResolver $cohortResolver,
        private HospitalAccessInterface $hospitalAccess,
    ) {
    }

    /**
     * @return list<DataQualityHospitalSnapshot>
     */
    public function resolve(StatisticsFilter $filter, ?User $user): array
    {
        return match ($filter->scope) {
            StatisticsFilterScope::Public => $this->populationQuery->fetchAll(),
            StatisticsFilterScope::Hospital => null !== $filter->hospitalId
                ? $this->populationQuery->fetchByIds([$filter->hospitalId])
                : [],
            StatisticsFilterScope::State => null !== $filter->stateId
                ? $this->populationQuery->fetchByStateId($filter->stateId)
                : [],
            StatisticsFilterScope::DispatchArea => null !== $filter->dispatchAreaId
                ? $this->populationQuery->fetchByDispatchAreaId($filter->dispatchAreaId)
                : [],
            StatisticsFilterScope::HospitalCohort => $filter->cohortType instanceof HospitalCohortKey
                ? $this->populationQuery->fetchByCohort($this->cohortResolver->resolve($filter->cohortType))
                : [],
            StatisticsFilterScope::MyHospitals => $this->resolveMyHospitals($user),
        };
    }

    /**
     * @return list<DataQualityHospitalSnapshot>
     */
    private function resolveMyHospitals(?User $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        $ids = $this->hospitalAccess->accessibleHospitalIds($user);

        return $this->populationQuery->fetchByIds($ids);
    }
}
