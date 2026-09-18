<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Statistics\Application\TopList\TopListCatalogCrossReference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CatalogActionFactoryTest extends TestCase
{
    public function testSecondaryTransportActionLinksToAllocationListFilter(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(3))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['secondaryTransport'] ?? null) === 42 => '/explore/allocation?secondaryTransport=42',
                'app_stats_insights_show' === $route && ($params['dimension'] ?? null) === 'secondary-transports' && ($params['id'] ?? null) === 42 => '/statistics/insights/secondary-transports/42',
                'app_stats_top_lists_show' === $route && ($params['report'] ?? null) === 'top_secondary_transports' => '/statistics/top-lists/top_secondary_transports',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forSecondaryTransport(42);

        self::assertCount(3, $actions);
        self::assertTrue($actions[0]->primary);
        self::assertSame('/explore/allocation?secondaryTransport=42', $actions[0]->url);
        self::assertSame('/statistics/insights/secondary-transports/42', $actions[1]->url);
        self::assertSame('/statistics/top-lists/top_secondary_transports', $actions[2]->url);
    }

    public function testIndicationActionsIncludeInsightsAndTopList(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(3))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['indication'] ?? null) === 101 => '/explore/allocation?indication=101',
                'app_stats_insights_show' === $route && ($params['dimension'] ?? null) === 'indications' && ($params['id'] ?? null) === 7 => '/statistics/insights/indications/7',
                'app_stats_top_lists_show' === $route && ($params['report'] ?? null) === 'top_diagnoses' => '/statistics/top-lists/top_diagnoses',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forIndication(7, 101);

        self::assertCount(3, $actions);
        self::assertSame('/explore/allocation?indication=101', $actions[0]->url);
        self::assertSame('/statistics/insights/indications/7', $actions[1]->url);
        self::assertSame('/statistics/top-lists/top_diagnoses', $actions[2]->url);
        self::assertSame('tabler:list-numbers', $actions[2]->icon);
    }

    public function testIndicationActionsCanIncludeReviewWorklist(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(4))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match ($route) {
                'app_explore_allocation_list' => '/explore/allocation',
                'app_stats_insights_show' => '/statistics/insights/indications/7',
                'app_explore_indication_raw_review_worklist' => '/explore/indication/raw/review',
                'app_stats_top_lists_show' => '/statistics/top-lists/top_diagnoses',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forIndication(7, 101, true);

        self::assertCount(4, $actions);
        self::assertSame('/explore/indication/raw/review', $actions[2]->url);
        self::assertSame('/statistics/top-lists/top_diagnoses', $actions[3]->url);
    }

    public function testDepartmentActionLinksToAllocationListFilterAndTopList(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(3))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['department'] ?? null) === 9 => '/explore/allocation?department=9',
                'app_stats_insights_show' === $route && ($params['dimension'] ?? null) === 'departments' && ($params['id'] ?? null) === 9 => '/statistics/insights/departments/9',
                'app_stats_top_lists_show' === $route && ($params['report'] ?? null) === 'top_departments' => '/statistics/top-lists/top_departments',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forDepartment(9);

        self::assertCount(3, $actions);
        self::assertSame('/explore/allocation?department=9', $actions[0]->url);
        self::assertSame('/statistics/insights/departments/9', $actions[1]->url);
        self::assertSame('/statistics/top-lists/top_departments', $actions[2]->url);
    }

    public function testIndicationGroupActionLinksToStatisticsDashboard(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_stats_insights_show', ['dimension' => 'indication-groups', 'id' => 3])
            ->willReturn('/statistics/insights/indication-groups/3');

        $actions = $this->factory($urlGenerator)->forIndicationGroup(3);

        self::assertCount(1, $actions);
        self::assertTrue($actions[0]->primary);
        self::assertSame('/statistics/insights/indication-groups/3', $actions[0]->url);
    }

    public function testStateActionsIncludeAllocationsHospitalsAndDispatchAreas(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(3))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['state'] ?? null) === 5 => '/explore/allocation?state=5',
                'app_explore_hospital_list' === $route && ($params['state'] ?? null) === 5 => '/explore/hospital?state=5',
                'app_explore_dispatch_area_list' === $route && ($params['state'] ?? null) === 5 => '/explore/dispatch_area?state=5',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forState(5);

        self::assertCount(3, $actions);
        self::assertSame('/explore/allocation?state=5', $actions[0]->url);
        self::assertSame('/explore/hospital?state=5', $actions[1]->url);
        self::assertSame('/explore/dispatch_area?state=5', $actions[2]->url);
    }

    public function testDispatchAreaActionLinksToAllocationListFilter(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_explore_allocation_list', ['dispatchArea' => 11])
            ->willReturn('/explore/allocation?dispatchArea=11');

        $actions = $this->factory($urlGenerator)->forDispatchArea(11);

        self::assertCount(1, $actions);
        self::assertSame('/explore/allocation?dispatchArea=11', $actions[0]->url);
    }

    public function testDispatchAreaAddsImportActionWhenExactlyOneHospitalIsAccessible(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['dispatchArea'] ?? null) === 11 => '/explore/allocation?dispatchArea=11',
                'app_import_index' === $route && ($params['hospitalId'] ?? null) === 77 => '/import?hospitalId=77',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forDispatchArea(11, 77);

        self::assertCount(2, $actions);
        self::assertSame('/explore/allocation?dispatchArea=11', $actions[0]->url);
        self::assertSame('/import?hospitalId=77', $actions[1]->url);
        self::assertSame('tabler:file-import', $actions[1]->icon);
    }

    public function testHospitalActionsFollowPermissions(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(4))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['hospitalFilter'] ?? null) === '9' => '/explore/allocation?hospitalFilter=9',
                'app_import_index' === $route && ($params['hospitalId'] ?? null) === 9 => '/import?hospitalId=9',
                'app_explore_dispatch_area_show' === $route && ($params['publicId'] ?? null) === 'da-public-id' => '/explore/dispatch_area/da-public-id',
                'app_stats_benchmarking' === $route
                    && ($params['scope'] ?? null) === 'hospital'
                    && ($params['hospital'] ?? null) === 9
                    && ($params['period'] ?? null) === 'all'
                    && ($params['comparison_scope'] ?? null) === 'hospital_cohort'
                    && ($params['comparison_period'] ?? null) === 'all_time' => '/statistics/benchmarking?scope=hospital',
                default => throw new \InvalidArgumentException($route.' '.json_encode($params)),
            });

        $actions = $this->factory($urlGenerator)->forHospital(9, 'da-public-id', true, true, true);

        self::assertCount(4, $actions);
        self::assertTrue($actions[0]->primary);
        self::assertSame('/explore/allocation?hospitalFilter=9', $actions[0]->url);
        self::assertSame('/import?hospitalId=9', $actions[1]->url);
        self::assertSame('/explore/dispatch_area/da-public-id', $actions[2]->url);
        self::assertSame('/statistics/benchmarking?scope=hospital', $actions[3]->url);
        self::assertSame('tabler:scale', $actions[3]->icon);
    }

    public function testHospitalActionsOmitRestrictedLinks(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_explore_dispatch_area_show', ['publicId' => 'da-public-id'])
            ->willReturn('/explore/dispatch_area/da-public-id');

        $actions = $this->factory($urlGenerator)->forHospital(9, 'da-public-id', false, false, false);

        self::assertCount(1, $actions);
        self::assertFalse($actions[0]->primary);
        self::assertSame('/explore/dispatch_area/da-public-id', $actions[0]->url);
    }

    public function testInfectionActionLinksToAllocationListFilterAndTopList(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(3))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['infection'] ?? null) === 8 => '/explore/allocation?infection=8',
                'app_stats_insights_show' === $route && ($params['dimension'] ?? null) === 'infections' && ($params['id'] ?? null) === 8 => '/statistics/insights/infections/8',
                'app_stats_top_lists_show' === $route && ($params['report'] ?? null) === 'top_infections' => '/statistics/top-lists/top_infections',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forInfection(8);

        self::assertCount(3, $actions);
        self::assertSame('/explore/allocation?infection=8', $actions[0]->url);
        self::assertSame('/statistics/insights/infections/8', $actions[1]->url);
        self::assertSame('/statistics/top-lists/top_infections', $actions[2]->url);
    }

    public function testCatalogListActionForMappedDimension(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_stats_top_lists_show', ['report' => 'top_specialities'])
            ->willReturn('/statistics/top-lists/top_specialities');

        $action = $this->factory($urlGenerator)->forCatalogList(CatalogDimensionKey::Speciality);

        self::assertNotNull($action);
        self::assertSame('/statistics/top-lists/top_specialities', $action->url);
    }

    public function testCatalogListActionIsNullWhenDimensionHasNoTopList(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        self::assertNull($this->factory($urlGenerator)->forCatalogList(CatalogDimensionKey::Hospital));
    }

    private function factory(UrlGeneratorInterface $urlGenerator): CatalogActionFactory
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('label');

        return new CatalogActionFactory($urlGenerator, $translator, new TopListCatalogCrossReference());
    }
}
