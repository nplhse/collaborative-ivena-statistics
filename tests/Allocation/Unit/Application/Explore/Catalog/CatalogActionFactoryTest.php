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
}
