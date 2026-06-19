<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Overview;

use App\Statistics\Application\Overview\OverviewPortalNavigationFactory;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
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
                $viewKey = $params['viewKey'] ?? '';
                unset($params['viewKey']);

                return '/statistics/analytics/view/'.$viewKey.'?'.http_build_query($params);
            },
        );

        $this->urlBuilder = new StatisticsNavigationUrlBuilder($router);
        $this->factory = new OverviewPortalNavigationFactory();
    }

    public function testResourcesOverTimeTargetPointsToClinicalRatesByMonth(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&dimension=resources');
        $url = $this->urlBuilder->buildFromTarget($request, $this->factory->resourcesOverTimeTarget());

        self::assertStringContainsString('/statistics/analytics/view/clinical_rates_by_month', $url);
        self::assertStringNotContainsString('dimension=resources', $url);
    }

    public function testClinicalFeaturesOverTimeTargetPointsToClinicalRatesByMonth(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&dimension=features');
        $url = $this->urlBuilder->buildFromTarget($request, $this->factory->clinicalFeaturesOverTimeTarget());

        self::assertStringContainsString('/statistics/analytics/view/clinical_rates_by_month', $url);
        self::assertStringNotContainsString('dimension=features', $url);
    }

    public function testClinicalFeaturesTargetRemovesExistingGenericAnalysisOverrides(): void
    {
        $request = Request::create('/statistics/?'.http_build_query([
            GenericAnalysisQueryKeys::PRIMARY => 'hour',
            GenericAnalysisQueryKeys::METRICS => ['count', 'percent_of_total'],
            GenericAnalysisQueryKeys::VISUAL_METRIC => 'percent_of_total',
        ]));
        $url = $this->urlBuilder->buildFromTarget($request, $this->factory->clinicalFeaturesOverTimeTarget());

        self::assertStringContainsString('/statistics/analytics/view/clinical_rates_by_month', $url);
        self::assertStringNotContainsString('percent_of_total', $url);
        self::assertStringNotContainsString('ga_primary=hour', $url);
    }

    public function testBuildProvidesAnalyticsTargetsForOverviewCharts(): void
    {
        $request = Request::create('/statistics/?scope=public&period=all_time&report=legacy');
        $navigation = $this->factory->build();

        self::assertCount(1, $navigation->timeSeries);
        self::assertCount(1, $navigation->heatmapDayTime);
        self::assertCount(1, $navigation->heatmapShift);
        self::assertCount(1, $navigation->ageGroups);

        $timeSeriesUrl = $this->urlBuilder->buildFromTarget($request, $navigation->timeSeries[0]);
        self::assertStringContainsString('/statistics/analytics/view/allocations_by_month', $timeSeriesUrl);
        self::assertStringNotContainsString('report=legacy', $timeSeriesUrl);

        $heatmapDayUrl = $this->urlBuilder->buildFromTarget($request, $navigation->heatmapDayTime[0]);
        self::assertStringContainsString('/statistics/analytics/view/hour_weekday_heatmap', $heatmapDayUrl);

        $heatmapShiftUrl = $this->urlBuilder->buildFromTarget($request, $navigation->heatmapShift[0]);
        self::assertStringContainsString('/statistics/analytics/view/allocations_by_weekday', $heatmapShiftUrl);

        $ageGroupsUrl = $this->urlBuilder->buildFromTarget($request, $navigation->ageGroups[0]);
        self::assertStringContainsString('/statistics/analytics/view/age_group_distribution', $ageGroupsUrl);
    }
}
