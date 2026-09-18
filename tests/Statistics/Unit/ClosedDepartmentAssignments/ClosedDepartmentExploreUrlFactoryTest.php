<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosedDepartmentAssignments;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentExploreUrlFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ClosedDepartmentExploreUrlFactoryTest extends TestCase
{
    public function testIncludesClosedFlagPeriodAndHospitalScope(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                self::callback(static fn (array $params): bool => 1 === $params['departmentWasClosed']
                    && '42' === $params['hospitalFilter']
                    && '2026-03-01' === $params['createdFrom']
                    && '2026-03-31' === $params['createdUntil']
                    && 9 === $params['department']),
            )
            ->willReturn('/explore/allocation');

        $factory = new ClosedDepartmentExploreUrlFactory($router);
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            42,
            null,
            StatisticsFilterPeriod::Month,
            2026,
            3,
        );
        $period = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-03-01 00:00:00'),
            new \DateTimeImmutable('2026-04-01 00:00:00'),
        );

        self::assertSame('/explore/allocation', $factory->listUrl($filter, $period, ['department' => 9]));
    }

    public function testOmitsPeriodParamsWhenBoundsAreOpen(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                ['departmentWasClosed' => 1],
            )
            ->willReturn('/explore/allocation');

        $factory = new ClosedDepartmentExploreUrlFactory($router);
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
        );

        $factory->listUrl($filter, new StatisticsPeriodBounds(null));
    }
}
