<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Overview;

use App\Statistics\AnalysisExplorer\Application\ExplorerLegacyAnalyticsViewMapper;
use App\Statistics\Application\Overview\OverviewPortalNavigationFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OverviewPortalNavigationFactoryTest extends TestCase
{
    private OverviewPortalNavigationFactory $factory;

    private StatisticsNavigationUrlBuilder $urlBuilder;

    protected function setUp(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static function (string $name, array $params = []): string {
                if ('app_stats_analysis_explorer_view' === $name) {
                    $view = $params['view'] ?? '';
                    unset($params['view']);

                    return '/statistics/analysis/explorer/'.$view.'?'.http_build_query($params);
                }

                return '/statistics/analysis/library?'.http_build_query($params);
            },
        );

        $this->urlBuilder = new StatisticsNavigationUrlBuilder($router);
        $this->factory = new OverviewPortalNavigationFactory(new ExplorerLegacyAnalyticsViewMapper());
    }

    public function testResourcesOverTimeTargetPointsToLibraryWhileSliceIsPaused(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&dimension=resources');
        $url = $this->urlBuilder->buildFromTarget($request, $this->factory->resourcesOverTimeTarget());

        self::assertStringContainsString('/statistics/analysis/library', $url);
        self::assertStringNotContainsString('dimension=resources', $url);
    }

    public function testClinicalFeaturesOverTimeTargetPointsToLibraryWhileSliceIsPaused(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&dimension=features');
        $url = $this->urlBuilder->buildFromTarget($request, $this->factory->clinicalFeaturesOverTimeTarget());

        self::assertStringContainsString('/statistics/analysis/library', $url);
        self::assertStringNotContainsString('dimension=features', $url);
    }

    public function testBuildKeepsTimeSeriesExplorerTargetAndRoutesOtherChartsToLibrary(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&report=legacy');
        $navigation = $this->factory->build();

        self::assertCount(1, $navigation->timeSeries);
        self::assertCount(1, $navigation->heatmapHour);
        self::assertCount(1, $navigation->heatmapWeekday);
        self::assertCount(1, $navigation->ageGroups);
        self::assertCount(1, $navigation->transportTime);

        $timeSeriesUrl = $this->urlBuilder->buildFromTarget($request, $navigation->timeSeries[0]);
        self::assertStringContainsString('/statistics/analysis/explorer/allocations-over-time', $timeSeriesUrl);
        self::assertStringContainsString('period=all_time', $timeSeriesUrl);
        self::assertStringNotContainsString('report=legacy', $timeSeriesUrl);

        $heatmapHourUrl = $this->urlBuilder->buildFromTarget($request, $navigation->heatmapHour[0]);
        self::assertStringContainsString('/statistics/analysis/library', $heatmapHourUrl);

        $heatmapWeekdayUrl = $this->urlBuilder->buildFromTarget($request, $navigation->heatmapWeekday[0]);
        self::assertStringContainsString('/statistics/analysis/library', $heatmapWeekdayUrl);

        $ageGroupsUrl = $this->urlBuilder->buildFromTarget($request, $navigation->ageGroups[0]);
        self::assertStringContainsString('/statistics/analysis/library', $ageGroupsUrl);

        $transportTimeUrl = $this->urlBuilder->buildFromTarget($request, $navigation->transportTime[0]);
        self::assertStringContainsString('/statistics/analysis/library', $transportTimeUrl);
    }

    public function testTimeSeriesDrillDownPreservesRequestPeriodWithoutForcingAllTime(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all');
        $navigation = $this->factory->build();

        $timeSeriesUrl = $this->urlBuilder->buildFromTarget($request, $navigation->timeSeries[0]);

        self::assertStringContainsString('/statistics/analysis/explorer/allocations-over-time', $timeSeriesUrl);
        self::assertStringContainsString('period=all', $timeSeriesUrl);
        self::assertStringNotContainsString('period=all_time', $timeSeriesUrl);
    }
}
