<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('View allocations');

        $factory = new CatalogActionFactory($urlGenerator, $translator);
        $actions = $factory->forSecondaryTransport(42);

        self::assertCount(1, $actions);
        self::assertTrue($actions[0]->primary);
        self::assertSame('/explore/allocation?secondaryTransport=42', $actions[0]->url);
    }

    public function testIndicationActionsIncludeInsights(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['indication'] ?? null) === 101 => '/explore/allocation?indication=101',
                'app_stats_indication_dashboard' === $route && ($params['indicationId'] ?? null) === 7 => '/statistics/indication/7',
                default => throw new \InvalidArgumentException($route),
            });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('label');

        $factory = new CatalogActionFactory($urlGenerator, $translator);
        $actions = $factory->forIndication(7, 101);

        self::assertCount(2, $actions);
        self::assertSame('/explore/allocation?indication=101', $actions[0]->url);
        self::assertSame('/statistics/indication/7', $actions[1]->url);
    }

    public function testIndicationActionsCanIncludeReviewWorklist(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(3))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match ($route) {
                'app_explore_allocation_list' => '/explore/allocation',
                'app_stats_indication_dashboard' => '/statistics/indication/7',
                'app_explore_indication_raw_review_worklist' => '/explore/indication/raw/review',
                default => throw new \InvalidArgumentException($route),
            });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('label');

        $factory = new CatalogActionFactory($urlGenerator, $translator);
        $actions = $factory->forIndication(7, 101, true);

        self::assertCount(3, $actions);
        self::assertSame('/explore/indication/raw/review', $actions[2]->url);
    }

    public function testDepartmentActionLinksToAllocationListFilter(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_explore_allocation_list', ['department' => 9])
            ->willReturn('/explore/allocation?department=9');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('View allocations');

        $factory = new CatalogActionFactory($urlGenerator, $translator);
        $actions = $factory->forDepartment(9);

        self::assertCount(1, $actions);
        self::assertSame('/explore/allocation?department=9', $actions[0]->url);
    }

    public function testIndicationGroupActionLinksToStatisticsDashboard(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_stats_indication_group_dashboard', ['groupId' => 3])
            ->willReturn('/statistics/indication-group/3');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Open group insights');

        $factory = new CatalogActionFactory($urlGenerator, $translator);
        $actions = $factory->forIndicationGroup(3);

        self::assertCount(1, $actions);
        self::assertTrue($actions[0]->primary);
        self::assertSame('/statistics/indication-group/3', $actions[0]->url);
    }

    public function testStateActionsIncludeAllocationsAndDispatchAreas(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturnCallback(static fn (string $route, array $params = []): string => match (true) {
                'app_explore_allocation_list' === $route && ($params['state'] ?? null) === 5 => '/explore/allocation?state=5',
                'app_explore_dispatch_area_list' === $route && ($params['state'] ?? null) === 5 => '/explore/dispatch_area?state=5',
                default => throw new \InvalidArgumentException($route),
            });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('label');

        $factory = new CatalogActionFactory($urlGenerator, $translator);
        $actions = $factory->forState(5);

        self::assertCount(2, $actions);
        self::assertSame('/explore/allocation?state=5', $actions[0]->url);
        self::assertSame('/explore/dispatch_area?state=5', $actions[1]->url);
    }

    public function testDispatchAreaActionLinksToAllocationListFilter(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_explore_allocation_list', ['dispatchArea' => 11])
            ->willReturn('/explore/allocation?dispatchArea=11');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('View allocations');

        $factory = new CatalogActionFactory($urlGenerator, $translator);
        $actions = $factory->forDispatchArea(11);

        self::assertCount(1, $actions);
        self::assertSame('/explore/allocation?dispatchArea=11', $actions[0]->url);
    }

    public function testInfectionActionLinksToAllocationListFilter(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_explore_allocation_list', ['infection' => 8])
            ->willReturn('/explore/allocation?infection=8');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('View allocations');

        $factory = new CatalogActionFactory($urlGenerator, $translator);
        $actions = $factory->forInfection(8);

        self::assertCount(1, $actions);
        self::assertSame('/explore/allocation?infection=8', $actions[0]->url);
    }
}
