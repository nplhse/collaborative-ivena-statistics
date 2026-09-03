<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\TopList;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Statistics\Application\TopList\TopListCatalogCrossReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TopListCatalogCrossReferenceTest extends TestCase
{
    #[DataProvider('dimensionProvider')]
    public function testMapsCatalogDimensionsToTopListKeys(CatalogDimensionKey $dimension, ?string $expectedKey): void
    {
        $crossReference = new TopListCatalogCrossReference();

        self::assertSame($expectedKey, $crossReference->topListKeyForDimension($dimension));
    }

    /**
     * @return iterable<string, array{CatalogDimensionKey, ?string}>
     */
    public static function dimensionProvider(): iterable
    {
        yield 'indication' => [CatalogDimensionKey::Indication, 'top_diagnoses'];
        yield 'department' => [CatalogDimensionKey::Department, 'top_departments'];
        yield 'speciality' => [CatalogDimensionKey::Speciality, 'top_specialities'];
        yield 'assignment' => [CatalogDimensionKey::Assignment, 'top_assignments'];
        yield 'occasion' => [CatalogDimensionKey::Occasion, 'top_occasions'];
        yield 'infection' => [CatalogDimensionKey::Infection, 'top_infections'];
        yield 'hospital' => [CatalogDimensionKey::Hospital, null];
        yield 'state' => [CatalogDimensionKey::State, null];
        yield 'dispatch area' => [CatalogDimensionKey::DispatchArea, null];
        yield 'secondary transport' => [CatalogDimensionKey::SecondaryTransport, null];
    }

    public function testIndicationRowsLinkToCatalogWithoutQueryMerge(): void
    {
        $publicId = '11111111-1111-4111-8111-111111111111';
        $target = new TopListCatalogCrossReference()->labelRowTarget('top_diagnoses', $publicId);

        self::assertNotNull($target);
        self::assertSame('app_explore_indication_show', $target->route);
        self::assertSame(['publicId' => $publicId], $target->params);
        self::assertFalse($target->mergeRequestQuery);
        self::assertNull(new TopListCatalogCrossReference()->labelRowTarget('top_diagnoses', null));
    }

    public function testSecondaryIndicationRowsUseTheSameCatalogRoute(): void
    {
        $publicId = '22222222-2222-4222-8222-222222222222';
        $target = new TopListCatalogCrossReference()->labelRowTarget('top_secondary_diagnoses', $publicId);

        self::assertNotNull($target);
        self::assertSame('app_explore_indication_show', $target->route);
        self::assertSame($publicId, $target->params['publicId']);
        self::assertFalse($target->mergeRequestQuery);
    }

    public function testDepartmentRowsLinkToCatalogWithoutQueryMerge(): void
    {
        $publicId = '11111111-1111-4111-8111-111111111111';
        $target = new TopListCatalogCrossReference()->labelRowTarget('top_departments', $publicId);

        self::assertNotNull($target);
        self::assertSame('app_explore_department_show', $target->route);
        self::assertSame(['publicId' => $publicId], $target->params);
        self::assertFalse($target->mergeRequestQuery);
        self::assertNull(new TopListCatalogCrossReference()->labelRowTarget('top_departments', null));
    }

    public function testCatalogListRoutes(): void
    {
        $crossReference = new TopListCatalogCrossReference();

        self::assertSame('app_explore_indication_list', $crossReference->catalogListRoute('top_diagnoses'));
        self::assertSame('app_explore_indication_list', $crossReference->catalogListRoute('top_secondary_diagnoses'));
        self::assertSame('app_explore_department_list', $crossReference->catalogListRoute('top_departments'));
        self::assertSame('app_explore_speciality_list', $crossReference->catalogListRoute('top_specialities'));
        self::assertSame('app_explore_assignment_list', $crossReference->catalogListRoute('top_assignments'));
        self::assertSame('app_explore_occasion_list', $crossReference->catalogListRoute('top_occasions'));
        self::assertSame('app_explore_infection_list', $crossReference->catalogListRoute('top_infections'));
        self::assertNull($crossReference->catalogListRoute('unknown'));
    }

    #[DataProvider('catalogShowRouteProvider')]
    public function testMappedRowsLinkToCatalogShowRoutes(string $topListKey, string $expectedRoute): void
    {
        $publicId = '33333333-3333-4333-8333-333333333333';
        $target = new TopListCatalogCrossReference()->labelRowTarget($topListKey, $publicId);

        self::assertNotNull($target);
        self::assertSame($expectedRoute, $target->route);
        self::assertSame(['publicId' => $publicId], $target->params);
        self::assertFalse($target->mergeRequestQuery);
        self::assertNull(new TopListCatalogCrossReference()->labelRowTarget($topListKey, null));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function catalogShowRouteProvider(): iterable
    {
        yield 'speciality' => ['top_specialities', 'app_explore_speciality_show'];
        yield 'assignment' => ['top_assignments', 'app_explore_assignment_show'];
        yield 'occasion' => ['top_occasions', 'app_explore_occasion_show'];
        yield 'infection' => ['top_infections', 'app_explore_infection_show'];
    }

    public function testUnknownTopListHasNoRowTarget(): void
    {
        self::assertNull(new TopListCatalogCrossReference()->labelRowTarget('unknown', '33333333-3333-4333-8333-333333333333'));
    }
}
