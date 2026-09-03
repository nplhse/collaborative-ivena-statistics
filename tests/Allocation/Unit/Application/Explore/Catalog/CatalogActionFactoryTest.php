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
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_explore_allocation_list', ['secondaryTransport' => 42])
            ->willReturn('/explore/allocation?secondaryTransport=42');

        $actions = $this->factory($urlGenerator)->forSecondaryTransport(42);

        self::assertCount(1, $actions);
        self::assertTrue($actions[0]->primary);
        self::assertSame('/explore/allocation?secondaryTransport=42', $actions[0]->url);
    }

    public function testIndicationActionsIncludeInsightsAndTopList(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(3))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['indication'] ?? null) === 101 => '/explore/allocation?indication=101',
                'app_stats_indication_dashboard' === $route && ($params['indicationId'] ?? null) === 7 => '/statistics/indication/7',
                'app_stats_top_lists_show' === $route && ($params['report'] ?? null) === 'top_diagnoses' => '/statistics/top-lists/top_diagnoses',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forIndication(7, 101);

        self::assertCount(3, $actions);
        self::assertSame('/explore/allocation?indication=101', $actions[0]->url);
        self::assertSame('/statistics/indication/7', $actions[1]->url);
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
                'app_stats_indication_dashboard' => '/statistics/indication/7',
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
        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['department'] ?? null) === 9 => '/explore/allocation?department=9',
                'app_stats_top_lists_show' === $route && ($params['report'] ?? null) === 'top_departments' => '/statistics/top-lists/top_departments',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forDepartment(9);

        self::assertCount(2, $actions);
        self::assertSame('/explore/allocation?department=9', $actions[0]->url);
        self::assertSame('/statistics/top-lists/top_departments', $actions[1]->url);
    }

    public function testIndicationGroupActionLinksToStatisticsDashboard(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_stats_indication_group_dashboard', ['groupId' => 3])
            ->willReturn('/statistics/indication-group/3');

        $actions = $this->factory($urlGenerator)->forIndicationGroup(3);

        self::assertCount(1, $actions);
        self::assertTrue($actions[0]->primary);
        self::assertSame('/statistics/indication-group/3', $actions[0]->url);
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

    public function testInfectionActionLinksToAllocationListFilterAndTopList(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['infection'] ?? null) === 8 => '/explore/allocation?infection=8',
                'app_stats_top_lists_show' === $route && ($params['report'] ?? null) === 'top_infections' => '/statistics/top-lists/top_infections',
                default => throw new \InvalidArgumentException($route),
            });

        $actions = $this->factory($urlGenerator)->forInfection(8);

        self::assertCount(2, $actions);
        self::assertSame('/explore/allocation?infection=8', $actions[0]->url);
        self::assertSame('/statistics/top-lists/top_infections', $actions[1]->url);
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
