<?php

declare(strict_types=1);

namespace App\Encounter\Application\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\ValueRange;
use App\Allocation\Application\Explore\DataCatalog\CodeLabels;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogContributorInterface;
use App\Allocation\Application\Explore\DataCatalog\DataObjectDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableGroupDefinition;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\Encounter;

/**
 * The encounter base data in the Explore data catalog (#1415), shown only to users who may see cases of
 * any hospital. The case key and its generation are pseudonyms for matching and are left out on purpose,
 * as is the user who set the assignment.
 */
final readonly class EncounterDataCatalogContributor implements DataCatalogContributorInterface
{
    private const string DOMAIN = 'encounter';

    #[\Override]
    public function dataObjects(): array
    {
        return [
            new DataObjectDefinition('encounter', Encounter::class, self::DOMAIN, 'tabler:stethoscope', [
                new VariableGroupDefinition('time', [
                    new VariableDefinition('encounter.anchor_at', 'anchorAt'),
                ]),
                new VariableGroupDefinition('patient', [
                    new VariableDefinition('encounter.age', 'age', new ValueRange(1, 99, 'data_catalog.unit.years')),
                    new VariableDefinition('encounter.sex', 'sex', codeLabels: CodeLabels::prefixed('encounter.review.value.sex_', self::DOMAIN)),
                ]),
                new VariableGroupDefinition('assignment', [
                    new VariableDefinition('encounter.hospital', 'hospital'),
                    new VariableDefinition('encounter.allocation', 'allocation'),
                    new VariableDefinition('encounter.allocation_assigned_at', 'allocationAssignedAt'),
                ]),
                new VariableGroupDefinition('record', [
                    new VariableDefinition('encounter.id', 'id'),
                    new VariableDefinition('encounter.created_at', 'createdAt'),
                ]),
            ], grantedAttribute: EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL),
        ];
    }
}
