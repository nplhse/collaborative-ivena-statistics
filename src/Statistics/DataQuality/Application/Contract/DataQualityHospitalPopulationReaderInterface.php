<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Application\Contract;

use App\Statistics\Application\Cohort\HospitalCohort;
use App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot;

interface DataQualityHospitalPopulationReaderInterface
{
    /**
     * @return list<DataQualityHospitalSnapshot>
     */
    public function fetchAll(): array;

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<DataQualityHospitalSnapshot>
     */
    public function fetchByIds(array $hospitalIds): array;

    /**
     * @return list<DataQualityHospitalSnapshot>
     */
    public function fetchByStateId(int $stateId): array;

    /**
     * @return list<DataQualityHospitalSnapshot>
     */
    public function fetchByDispatchAreaId(int $dispatchAreaId): array;

    /**
     * @return list<DataQualityHospitalSnapshot>
     */
    public function fetchByCohort(HospitalCohort $cohort): array;
}
