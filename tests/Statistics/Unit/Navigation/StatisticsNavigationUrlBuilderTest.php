<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Navigation;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StatisticsNavigationUrlBuilderTest extends TestCase
{
    public function testBuildParamsRemovesDependentKeysAndAppliesReplacements(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router
            ->expects(self::once())
            ->method('generate')
            ->with(
                'app_stats_benchmarking',
                self::callback(static fn (array $params): bool => 'public' === ($params['scope'] ?? null)
                    && !isset($params['hospital'])
                    && 'all' === ($params['period'] ?? null)),
            )
            ->willReturn('/statistics/benchmarking?scope=public&period=all');

        $builder = new StatisticsNavigationUrlBuilder($router);
        $request = Request::create('/statistics/benchmarking', Request::METHOD_GET, [
            'scope' => 'hospital',
            'hospital' => '5',
            'period' => 'all',
        ]);

        $params = $builder->buildParams(
            $request,
            'app_stats_benchmarking',
            [StatisticsQueryKeys::SCOPE => 'public'],
            StatisticsQueryKeys::REMOVE_SCOPE_DEPENDENT,
        );

        self::assertSame('public', $params['scope']);
        self::assertSame('all', $params['period']);
        self::assertArrayNotHasKey('hospital', $params);
    }

    public function testBuildFromTargetPassesExplorerViewSlugToRouter(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router
            ->expects(self::once())
            ->method('generate')
            ->with(
                'app_stats_analysis_explorer_view',
                self::callback(static fn (array $params): bool => 'allocations-over-time' === ($params['view'] ?? null)),
            )
            ->willReturn('/statistics/analysis/explorer/allocations-over-time');

        $builder = new StatisticsNavigationUrlBuilder($router);
        $request = Request::create('/statistics/?scope=public&period=all_time');
        $target = new StatisticWidgetNavigationTarget(
            'stats.nav.example',
            'app_stats_analysis_explorer_view',
            ['view' => 'allocations-over-time'],
        );

        $builder->buildFromTarget($request, $target);
    }
}
