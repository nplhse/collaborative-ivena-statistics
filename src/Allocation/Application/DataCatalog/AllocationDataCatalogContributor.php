<?php

declare(strict_types=1);

namespace App\Allocation\Application\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\ValueRange;
use App\Allocation\Application\Explore\DataCatalog\CodeLabels;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogContributorInterface;
use App\Allocation\Application\Explore\DataCatalog\DataObjectDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableGroupDefinition;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\State;

/**
 * Core data objects and reference objects of the Allocation context (#1415, #1492). Surrogate and
 * public ids, the case id hash, free-text notes, the import reference, owners and audit fields are
 * left out on purpose.
 */
final readonly class AllocationDataCatalogContributor implements DataCatalogContributorInterface
{
    private const string DOMAIN = 'allocation';

    #[\Override]
    public function dataObjects(): array
    {
        return [$this->allocation(), $this->mciCase(), $this->hospital(), $this->dispatchArea(), $this->state()];
    }

    private function allocation(): DataObjectDefinition
    {
        $enumLabels = CodeLabels::fromEnumLabel('messages');

        return new DataObjectDefinition('allocation', Allocation::class, self::DOMAIN, 'tabler:ambulance', [
            new VariableGroupDefinition('time', [
                new VariableDefinition('allocation.created_at', 'createdAt'),
                new VariableDefinition('allocation.arrival_at', 'arrivalAt'),
            ]),
            new VariableGroupDefinition('patient', [
                new VariableDefinition('allocation.age', 'age', new ValueRange(1, 99, 'data_catalog.unit.years')),
                new VariableDefinition('allocation.gender', 'gender', codeLabels: $enumLabels),
                new VariableDefinition('allocation.is_pregnant', 'isPregnant'),
            ]),
            new VariableGroupDefinition('urgency', [
                new VariableDefinition('allocation.urgency', 'urgency', codeLabels: $enumLabels),
            ]),
            new VariableGroupDefinition('clinical', [
                new VariableDefinition('allocation.indication_raw', 'indicationRaw'),
                new VariableDefinition('allocation.indication_normalized', 'indicationNormalized'),
                new VariableDefinition('allocation.secondary_indication_raw', 'secondaryIndicationRaw'),
                new VariableDefinition('allocation.secondary_indication_normalized', 'secondaryIndicationNormalized'),
                new VariableDefinition('allocation.is_cpr', 'isCPR'),
                new VariableDefinition('allocation.is_ventilated', 'isVentilated'),
                new VariableDefinition('allocation.is_shock', 'isShock'),
                new VariableDefinition('allocation.infection', 'infection'),
                new VariableDefinition('allocation.assessment_airway', 'assessment.airway', codeLabels: $enumLabels),
                new VariableDefinition('allocation.assessment_breathing', 'assessment.breathing', codeLabels: $enumLabels),
                new VariableDefinition('allocation.assessment_circulation', 'assessment.circulation', codeLabels: $enumLabels),
                new VariableDefinition('allocation.assessment_disability', 'assessment.disability', codeLabels: $enumLabels),
            ]),
            new VariableGroupDefinition('resources', [
                new VariableDefinition('allocation.requires_resus', 'requiresResus'),
                new VariableDefinition('allocation.requires_cathlab', 'requiresCathlab'),
            ]),
            new VariableGroupDefinition('transport', [
                new VariableDefinition('allocation.transport_type', 'transportType', codeLabels: $enumLabels),
                new VariableDefinition('allocation.is_with_physician', 'isWithPhysician'),
                new VariableDefinition('allocation.secondary_transport', 'secondaryTransport'),
            ]),
            new VariableGroupDefinition('destination', [
                new VariableDefinition('allocation.hospital', 'hospital'),
                new VariableDefinition('allocation.speciality', 'speciality'),
                new VariableDefinition('allocation.department', 'department'),
                new VariableDefinition('allocation.department_was_closed', 'departmentWasClosed'),
            ]),
            new VariableGroupDefinition('context', [
                new VariableDefinition('allocation.assignment', 'assignment'),
                new VariableDefinition('allocation.occasion', 'occasion'),
                new VariableDefinition('allocation.is_work_accident', 'isWorkAccident'),
            ]),
            new VariableGroupDefinition('geography', [
                new VariableDefinition('allocation.dispatch_area', 'dispatchArea'),
                new VariableDefinition('allocation.state', 'state'),
            ]),
        ]);
    }

    private function mciCase(): DataObjectDefinition
    {
        $enumLabels = CodeLabels::fromEnumLabel('messages');

        return new DataObjectDefinition('mci_case', MciCase::class, self::DOMAIN, 'tabler:users-group', [
            new VariableGroupDefinition('time', [
                new VariableDefinition('mci_case.created_at', 'createdAt'),
                new VariableDefinition('mci_case.arrival_at', 'arrivalAt'),
            ]),
            new VariableGroupDefinition('incident', [
                new VariableDefinition('mci_case.mci_id', 'mciId'),
                new VariableDefinition('mci_case.mci_title', 'mciTitle'),
            ]),
            new VariableGroupDefinition('patient', [
                new VariableDefinition('mci_case.age', 'age', new ValueRange(1, 99, 'data_catalog.unit.years')),
                new VariableDefinition('mci_case.gender', 'gender', codeLabels: $enumLabels),
                new VariableDefinition('mci_case.is_pregnant', 'isPregnant'),
            ]),
            new VariableGroupDefinition('urgency', [
                new VariableDefinition('mci_case.urgency', 'urgency', codeLabels: $enumLabels),
            ]),
            new VariableGroupDefinition('clinical', [
                new VariableDefinition('mci_case.indication_raw', 'indicationRaw'),
                new VariableDefinition('mci_case.indication_normalized', 'indicationNormalized'),
                new VariableDefinition('mci_case.is_cpr', 'isCPR'),
                new VariableDefinition('mci_case.is_ventilated', 'isVentilated'),
                new VariableDefinition('mci_case.is_shock', 'isShock'),
                new VariableDefinition('mci_case.infection', 'infection'),
            ]),
            new VariableGroupDefinition('resources', [
                new VariableDefinition('mci_case.requires_resus', 'requiresResus'),
                new VariableDefinition('mci_case.requires_cathlab', 'requiresCathlab'),
            ]),
            new VariableGroupDefinition('transport', [
                new VariableDefinition('mci_case.transport_type', 'transportType', codeLabels: $enumLabels),
                new VariableDefinition('mci_case.is_with_physician', 'isWithPhysician'),
            ]),
            new VariableGroupDefinition('destination', [
                new VariableDefinition('mci_case.hospital', 'hospital'),
                new VariableDefinition('mci_case.speciality', 'speciality'),
                new VariableDefinition('mci_case.department', 'department'),
                new VariableDefinition('mci_case.department_was_closed', 'departmentWasClosed'),
            ]),
            new VariableGroupDefinition('context', [
                new VariableDefinition('mci_case.occasion', 'occasion'),
            ]),
            new VariableGroupDefinition('geography', [
                new VariableDefinition('mci_case.dispatch_area', 'dispatchArea'),
                new VariableDefinition('mci_case.state', 'state'),
            ]),
        ]);
    }

    private function hospital(): DataObjectDefinition
    {
        return new DataObjectDefinition('hospital', Hospital::class, self::DOMAIN, 'tabler:building-hospital', [
            new VariableGroupDefinition('master_data', [
                new VariableDefinition('hospital.name', 'name'),
                new VariableDefinition('hospital.is_participating', 'isParticipating'),
                new VariableDefinition('hospital.participating_since', 'participatingSince'),
            ]),
            new VariableGroupDefinition('structure', [
                new VariableDefinition('hospital.size', 'size', codeLabels: CodeLabels::prefixed('hospital.size.', self::DOMAIN)),
                new VariableDefinition('hospital.beds', 'beds'),
                new VariableDefinition('hospital.tier', 'tier', codeLabels: CodeLabels::prefixed('hospital.tier.', self::DOMAIN)),
                new VariableDefinition('hospital.location', 'location', codeLabels: CodeLabels::prefixed('hospital.location.', self::DOMAIN)),
            ]),
            new VariableGroupDefinition('geography', [
                new VariableDefinition('hospital.postal_code', 'address.postalCode'),
                new VariableDefinition('hospital.city', 'address.city'),
                new VariableDefinition('hospital.latitude', 'latitude'),
                new VariableDefinition('hospital.longitude', 'longitude'),
                new VariableDefinition('hospital.dispatch_area', 'dispatchArea'),
                new VariableDefinition('hospital.state', 'state'),
            ]),
        ]);
    }

    private function dispatchArea(): DataObjectDefinition
    {
        return new DataObjectDefinition('dispatch_area', DispatchArea::class, self::DOMAIN, 'tabler:map-pin', [
            new VariableGroupDefinition('master_data', [
                new VariableDefinition('dispatch_area.name', 'name'),
            ]),
            new VariableGroupDefinition('geography', [
                new VariableDefinition('dispatch_area.state', 'state'),
            ]),
        ]);
    }

    private function state(): DataObjectDefinition
    {
        return new DataObjectDefinition('state', State::class, self::DOMAIN, 'tabler:map', [
            new VariableGroupDefinition('master_data', [
                new VariableDefinition('state.name', 'name'),
            ]),
        ]);
    }
}
