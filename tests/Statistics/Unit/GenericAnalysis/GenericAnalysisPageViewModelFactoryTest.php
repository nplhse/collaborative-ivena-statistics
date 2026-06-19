<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Application\Contract\CustomAnalysisAccessInterface;
use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisConfigResolver;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisMetricRequestResolver;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Registry\AnalysisDataSourceRegistry;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Controller\GenericAnalysisPageViewModelFactory;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisRouteContext;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisPageViewModelFactoryTest extends TestCase
{
    private GenericAnalysisPageViewModelFactory $factory;

    protected function setUp(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static function (string $name, array $params = []): string {
                $presetKey = $params['presetKey'] ?? '';
                unset($params['presetKey']);
                $query = http_build_query($params);

                return '/statistics/generic-analysis/'.$presetKey.('' !== $query ? '?'.$query : '');
            },
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $hospitalAccess = $this->createMock(HospitalAccessInterface::class);
        $hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(true);

        $metricRegistry = new MetricRegistry();
        $dimensionRegistry = new DimensionRegistry();
        $this->factory = new GenericAnalysisPageViewModelFactory(
            new AnalysisPresetRegistry(),
            $dimensionRegistry,
            $metricRegistry,
            new MetricCompatibilityChecker($metricRegistry, $dimensionRegistry),
            new GenericAnalysisDimensionPolicy($hospitalAccess, $dimensionRegistry),
            new AnalysisDataSourceRegistry(),
            new StatisticsNavigationUrlBuilder($router),
            $router,
            $translator,
        );
    }

    private function publicFilter(): StatisticsFilter
    {
        return new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );
    }

    public function testSaveTitleDraftWithoutSeriesUsesMetricAndPrimary(): void
    {
        $draft = $this->factory->buildSaveTitleDraft('month', null, 'count');

        $this->assertSame('stats.analytics_library.save.title_draft', $draft);
    }

    public function testSaveTitleDraftWithSeriesUsesPrimaryAndSeries(): void
    {
        $draft = $this->factory->buildSaveTitleDraft('hospital', 'month', 'count');

        $this->assertSame('stats.generic_analysis.chart.subtitle_with_series', $draft);
    }

    public function testPresetMenuUrlRemovesCustomQueryKeys(): void
    {
        $request = Request::create(
            '/statistics/generic-analysis/custom',
            Request::METHOD_GET,
            [
                'scope' => 'public',
                GenericAnalysisQueryKeys::PRIMARY => 'hour',
                GenericAnalysisQueryKeys::SERIES => 'urgency',
            ],
            [
                'presetKey' => 'custom',
                '_route' => 'app_stats_generic_analysis',
                '_route_params' => ['presetKey' => 'custom'],
            ],
        );

        $config = $this->resolvedConfig('hour', 'urgency');
        $viewModel = $this->factory->create($request, 'custom', $config, $this->publicFilter(), null);

        $monthItem = array_values(array_filter(
            $viewModel->presetMenu,
            static fn (array $item): bool => 'allocations_by_month' === $item['key'],
        ))[0];

        self::assertStringContainsString('/statistics/generic-analysis/allocations_by_month', $monthItem['url']);
        self::assertStringNotContainsString('ga_primary', $monthItem['url']);
        self::assertStringContainsString('scope=public', $monthItem['url']);
    }

    public function testFormActionUsesCurrentPresetRoute(): void
    {
        $request = Request::create(
            '/statistics/generic-analysis/allocations_by_month',
            Request::METHOD_GET,
            ['scope' => 'public'],
            [
                'presetKey' => 'allocations_by_month',
                '_route_params' => ['presetKey' => 'allocations_by_month'],
            ],
        );

        $config = $this->resolvedPresetConfig('allocations_by_month');
        $viewModel = $this->factory->create($request, 'allocations_by_month', $config, $this->publicFilter(), null);

        self::assertSame('/statistics/generic-analysis/allocations_by_month', $viewModel->formAction);
    }

    public function testPreservesMetricQueryFieldsInForm(): void
    {
        $request = Request::create(
            '/statistics/generic-analysis/allocations_by_month',
            Request::METHOD_GET,
            [
                'scope' => 'public',
                'ga_metrics' => ['count', 'percent_of_total'],
                'ga_visual_metric' => 'percent_of_total',
            ],
            [
                'presetKey' => 'allocations_by_month',
                '_route_params' => ['presetKey' => 'allocations_by_month'],
            ],
        );

        $config = $this->resolvedConfig('month', null);
        $viewModel = $this->factory->create($request, 'allocations_by_month', $config, $this->publicFilter(), null);

        $keys = array_column($viewModel->preservedQueryFields, 'key');
        self::assertContains('ga_metrics[]', $keys);
        self::assertContains('ga_visual_metric', $keys);
    }

    public function testAnalyticsViewDataSourceOptionsIncludeNavigationUrls(): void
    {
        $request = Request::create(
            '/statistics/analytics/view/allocations_by_month',
            Request::METHOD_GET,
            [
                'scope' => 'public',
                GenericAnalysisQueryKeys::DATA_SOURCE => 'allocations',
                GenericAnalysisQueryKeys::PRIMARY => 'month',
            ],
            [
                'viewKey' => 'allocations_by_month',
                '_route' => GenericAnalysisRouteContext::ANALYTICS_VIEW_ROUTE,
                '_route_params' => ['viewKey' => 'allocations_by_month'],
            ],
        );

        $config = $this->resolvedConfig('month', null);
        $viewModel = $this->factory->create(
            $request,
            'allocations_by_month',
            $config,
            $this->publicFilter(),
            null,
            GenericAnalysisRouteContext::forAnalyticsView('allocations_by_month'),
        );

        $hospitalsOption = array_values(array_filter(
            $viewModel->dataSourceOptions,
            static fn (array $option): bool => 'hospitals' === $option['value'],
        ))[0];

        self::assertArrayHasKey('url', $hospitalsOption);
        self::assertStringContainsString('ga_data_source=hospitals', $hospitalsOption['url']);
        self::assertStringContainsString('scope=public', $hospitalsOption['url']);
        self::assertStringNotContainsString('ga_primary', $hospitalsOption['url']);
        self::assertStringNotContainsString('ga_series', $hospitalsOption['url']);
    }

    private function resolvedPresetConfig(string $presetKey): ResolvedGenericAnalysisConfig
    {
        return $this->configResolver()->resolve(
            $presetKey,
            Request::create('/statistics/generic-analysis/'.$presetKey, Request::METHOD_GET, ['scope' => 'public']),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );
    }

    private function configResolver(): GenericAnalysisConfigResolver
    {
        $hospitalAccess = $this->createMock(HospitalAccessInterface::class);
        $hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(true);

        $metricRegistry = new MetricRegistry();
        $dimensionRegistry = new DimensionRegistry();

        $customAnalysisAccess = $this->createMock(CustomAnalysisAccessInterface::class);
        $customAnalysisAccess->method('canUseCustomAnalysis')->willReturn(true);

        $translator = $this->createMock(TranslatorInterface::class);

        return new GenericAnalysisConfigResolver(
            new AnalysisPresetRegistry(),
            $dimensionRegistry,
            new GenericAnalysisDimensionPolicy($hospitalAccess, $dimensionRegistry),
            new GenericAnalysisMetricRequestResolver(
                $metricRegistry,
                $dimensionRegistry,
                new MetricCompatibilityChecker($metricRegistry, $dimensionRegistry),
            ),
            $customAnalysisAccess,
            GenericAnalysisTestFixtures::configurationValidator($dimensionRegistry, $metricRegistry, $translator),
            new AnalysisDataSourceRegistry(),
            $translator,
        );
    }

    private function resolvedConfig(
        string $primary,
        ?string $series,
    ): ResolvedGenericAnalysisConfig {
        $resolver = $this->configResolver();

        $request = Request::create('/statistics/generic-analysis/custom', Request::METHOD_GET, array_filter([
            GenericAnalysisQueryKeys::PRIMARY => $primary,
            GenericAnalysisQueryKeys::SERIES => $series,
        ]));

        return $resolver->resolve(
            'custom',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );
    }
}
