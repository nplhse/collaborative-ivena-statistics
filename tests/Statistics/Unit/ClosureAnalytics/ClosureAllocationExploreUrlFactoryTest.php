<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\ClosureAllocationExploreUrlFactory;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ClosureAllocationExploreUrlFactoryTest extends TestCase
{
    public function testUsesEventHospitalAndBerlinCalendarDaysInPublicScope(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                [
                    'hospitalFilter' => '18',
                    'createdFrom' => '2026-05-01',
                    'createdUntil' => '2026-05-01',
                ],
            )
            ->willReturn('/explore/allocation');

        $factory = new ClosureAllocationExploreUrlFactory($router);
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
        );

        self::assertSame('/explore/allocation', $factory->listUrl(
            $filter,
            ClosureAnalyticsFilter::empty(),
            18,
            new \DateTimeImmutable('2026-05-01 10:00:00', $timezone),
            new \DateTimeImmutable('2026-05-01 12:00:00', $timezone),
        ));
    }

    public function testKeepsASingleSelectedHospitalFilter(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                self::callback(static fn (array $params): bool => '42' === $params['hospitalFilter']
                    && '2026-05-01' === $params['createdFrom']
                    && '2026-05-02' === $params['createdUntil']),
            )
            ->willReturn('/explore/allocation');

        $factory = new ClosureAllocationExploreUrlFactory($router);
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
        );

        $factory->listUrl(
            $filter,
            new ClosureAnalyticsFilter(hospitalIds: [42]),
            18,
            new \DateTimeImmutable('2026-05-01 23:00:00', $timezone),
            new \DateTimeImmutable('2026-05-02 01:00:00', $timezone),
        );
    }

    public function testUsesMyHospitalsWhenNoHospitalIsSelected(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                self::callback(static fn (array $params): bool => 'my_hospitals' === $params['hospitalFilter']),
            )
            ->willReturn('/explore/allocation');

        $factory = new ClosureAllocationExploreUrlFactory($router);
        $filter = new StatisticsFilter(
            StatisticsFilterScope::MyHospitals,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
        );

        $factory->listUrl(
            $filter,
            ClosureAnalyticsFilter::empty(),
            18,
            new \DateTimeImmutable('2026-05-01 10:00:00', $timezone),
            new \DateTimeImmutable('2026-05-01 12:00:00', $timezone),
        );
    }

    public function testUsesHospitalScopeIdOverTheEventHospital(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                self::callback(static fn (array $params): bool => '7' === $params['hospitalFilter']),
            )
            ->willReturn('/explore/allocation');

        $factory = new ClosureAllocationExploreUrlFactory($router);
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            7,
            null,
            StatisticsFilterPeriod::AllTime,
        );

        $factory->listUrl(
            $filter,
            new ClosureAnalyticsFilter(hospitalIds: [42, 18]),
            18,
            new \DateTimeImmutable('2026-05-02 00:00:00', $timezone),
            new \DateTimeImmutable('2026-05-02 00:00:00', $timezone),
        );
    }

    public function testFallsBackToTheEventHospitalWhenSeveralHospitalsAreSelected(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_explore_allocation_list',
                self::callback(static fn (array $params): bool => '18' === $params['hospitalFilter']
                    && '2026-05-01' === $params['createdUntil']),
            )
            ->willReturn('/explore/allocation');

        $factory = new ClosureAllocationExploreUrlFactory($router);

        $factory->listUrl(
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::AllTime),
            new ClosureAnalyticsFilter(hospitalIds: [3, 18]),
            18,
            new \DateTimeImmutable('2026-05-01 10:00:00', $timezone),
            new \DateTimeImmutable('2026-05-02 00:00:00', $timezone),
        );
    }
}
