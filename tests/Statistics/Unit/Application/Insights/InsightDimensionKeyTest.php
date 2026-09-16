<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionKey;
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

    public function testProjectionAndCatalogMappings(): void
    {
        self::assertSame('indication_normalized_id', InsightDimensionKey::Indications->projectionColumn());
        self::assertSame('indicationNormalizedId', InsightDimensionKey::IndicationGroups->projectionJoinProperty());
        self::assertSame(CatalogDimensionKey::Indication, InsightDimensionKey::Indications->catalogDimension());
        self::assertNull(InsightDimensionKey::IndicationGroups->catalogDimension());
        self::assertSame('top_diagnoses', InsightDimensionKey::Indications->topListKey());
        self::assertNull(InsightDimensionKey::IndicationGroups->topListKey());
        self::assertSame('assignment_id', InsightDimensionKey::Assignments->projectionColumn());
        self::assertSame('top_secondary_transports', InsightDimensionKey::SecondaryTransports->topListKey());
    }

    public function testCompareFamilyNestsGroupsUnderIndications(): void
    {
        self::assertSame(InsightDimensionKey::Indications, InsightDimensionKey::IndicationGroups->compareFamily());
        self::assertSame(InsightDimensionKey::Assignments, InsightDimensionKey::Assignments->compareFamily());
    }
}
