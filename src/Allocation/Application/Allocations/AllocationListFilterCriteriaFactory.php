<?php

declare(strict_types=1);

namespace App\Allocation\Application\Allocations;

use App\Allocation\Application\Export\DTO\AllocationListFilterCriteria;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use App\User\Domain\Entity\User;

final readonly class AllocationListFilterCriteriaFactory
{
    public function __construct(
        private AllocationListHospitalScopeResolver $hospitalScopeResolver,
    ) {
    }

    public function fromQuery(AllocationQueryParametersDTO $query, ?User $user): AllocationListFilterCriteria
    {
        $base = $query->toListFilterCriteria();
        $hospitalIds = $this->hospitalScopeResolver->resolveHospitalIdsFromQuery(
            $user,
            $query->hospitalFilter,
            $query->hospitalScope,
            $query->hospital,
        );

        return new AllocationListFilterCriteria(
            importId: $base->importId,
            tier: $base->tier,
            location: $base->location,
            size: $base->size,
            urgency: $base->urgency,
            dispatchArea: $base->dispatchArea,
            state: $base->state,
            requiresResus: $base->requiresResus,
            requiresCathlab: $base->requiresCathlab,
            indication: $base->indication,
            secondaryTransport: $base->secondaryTransport,
            isVentilated: $base->isVentilated,
            isShock: $base->isShock,
            isCPR: $base->isCPR,
            isPregnant: $base->isPregnant,
            isWorkAccident: $base->isWorkAccident,
            isInfectious: $base->isInfectious,
            infection: $base->infection,
            department: $base->department,
            speciality: $base->speciality,
            assignment: $base->assignment,
            occasion: $base->occasion,
            departmentWasClosed: $base->departmentWasClosed,
            transportType: $base->transportType,
            hospitalIds: $hospitalIds,
        );
    }
}
