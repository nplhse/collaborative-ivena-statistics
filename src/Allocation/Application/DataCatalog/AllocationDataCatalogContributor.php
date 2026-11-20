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

/**
 * Core data objects of the Allocation context (#1415). Surrogate and public ids, the case id hash,
 * free-text notes and the import reference are left out on purpose.
 */
final readonly class AllocationDataCatalogContributor implements DataCatalogContributorInterface
{
    private const string DOMAIN = 'allocation';

    #[\Override]
    public function dataObjects(): array
    {
        return [$this->allocation()];
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
}
