<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InsightDimensionKeyTest extends TestCase
{
    public function testRouteRequirementListsEverySlug(): void
    {
        $requirement = InsightDimensionKey::routeRequirement();

        foreach (InsightDimensionKey::cases() as $case) {
            self::assertStringContainsString($case->value, $requirement);
        }
    }

    #[DataProvider('mappingCases')]
    public function testProjectionAndCatalogMappings(
        InsightDimensionKey $key,
        string $column,
        string $joinProperty,
        ?CatalogDimensionKey $catalog,
        ?string $topListKey,
    ): void {
        self::assertSame($column, $key->projectionColumn());
        self::assertSame($joinProperty, $key->projectionJoinProperty());
        self::assertSame($catalog, $key->catalogDimension());
        self::assertSame($topListKey, $key->topListKey());
    }

    public function testCompareFamilyNestsGroupsUnderIndications(): void
    {
        self::assertSame(InsightDimensionKey::Indications, InsightDimensionKey::IndicationGroups->compareFamily());
        self::assertSame(InsightDimensionKey::Assignments, InsightDimensionKey::Assignments->compareFamily());
    }

    /**
     * @return list<array{InsightDimensionKey, string, string, ?CatalogDimensionKey, ?string}>
     */
    public static function mappingCases(): array
    {
        return [
            [InsightDimensionKey::Indications, 'indication_normalized_id', 'indicationNormalizedId', CatalogDimensionKey::Indication, 'top_diagnoses'],
            [InsightDimensionKey::IndicationGroups, 'indication_normalized_id', 'indicationNormalizedId', null, null],
            [InsightDimensionKey::Specialities, 'speciality_id', 'specialityId', CatalogDimensionKey::Speciality, 'top_specialities'],
            [InsightDimensionKey::Assignments, 'assignment_id', 'assignmentId', CatalogDimensionKey::Assignment, 'top_assignments'],
            [InsightDimensionKey::Departments, 'department_id', 'departmentId', CatalogDimensionKey::Department, 'top_departments'],
            [InsightDimensionKey::Occasions, 'occasion_id', 'occasionId', CatalogDimensionKey::Occasion, 'top_occasions'],
            [InsightDimensionKey::Infections, 'infection_id', 'infectionId', CatalogDimensionKey::Infection, 'top_infections'],
            [InsightDimensionKey::SecondaryTransports, 'secondary_transport_id', 'secondaryTransportId', CatalogDimensionKey::SecondaryTransport, 'top_secondary_transports'],
        ];
    }
}
