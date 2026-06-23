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
        $router = $this->createMock(UrlGeneratorInterface::class);
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

    public function testResourcesOverTimeTargetPointsToAllocationsOverTimeExplorerView(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&dimension=resources');
        $url = $this->urlBuilder->buildFromTarget($request, $this->factory->resourcesOverTimeTarget());

        self::assertStringContainsString('/statistics/analysis/explorer/allocations-over-time', $url);
        self::assertStringNotContainsString('dimension=resources', $url);
    }

    public function testClinicalFeaturesOverTimeTargetPointsToAllocationsOverTimeExplorerView(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&dimension=features');
        $url = $this->urlBuilder->buildFromTarget($request, $this->factory->clinicalFeaturesOverTimeTarget());

        self::assertStringContainsString('/statistics/analysis/explorer/allocations-over-time', $url);
        self::assertStringNotContainsString('dimension=features', $url);
    }

    public function testBuildProvidesExplorerTargetsForOverviewCharts(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&report=legacy');
        $navigation = $this->factory->build();

        self::assertCount(1, $navigation->timeSeries);
        self::assertCount(1, $navigation->heatmapDayTime);
        self::assertCount(1, $navigation->heatmapShift);
        self::assertCount(1, $navigation->ageGroups);

        $timeSeriesUrl = $this->urlBuilder->buildFromTarget($request, $navigation->timeSeries[0]);
        self::assertStringContainsString('/statistics/analysis/explorer/allocations-over-time', $timeSeriesUrl);
        self::assertStringNotContainsString('report=legacy', $timeSeriesUrl);

        $heatmapDayUrl = $this->urlBuilder->buildFromTarget($request, $navigation->heatmapDayTime[0]);
        self::assertStringContainsString('/statistics/analysis/explorer/day-time-bucket-distribution', $heatmapDayUrl);

        $heatmapShiftUrl = $this->urlBuilder->buildFromTarget($request, $navigation->heatmapShift[0]);
        self::assertStringContainsString('/statistics/analysis/explorer/allocations-by-weekday', $heatmapShiftUrl);

        $ageGroupsUrl = $this->urlBuilder->buildFromTarget($request, $navigation->ageGroups[0]);
        self::assertStringContainsString('/statistics/analysis/explorer/age-group-distribution', $ageGroupsUrl);
    }
}
