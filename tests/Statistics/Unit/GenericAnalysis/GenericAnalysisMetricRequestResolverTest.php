<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisMetricRequestResolver;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class GenericAnalysisMetricRequestResolverTest extends TestCase
{
    private GenericAnalysisMetricRequestResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GenericAnalysisMetricRequestResolver(
            new MetricRegistry(),
            new DimensionRegistry(),
            new MetricCompatibilityChecker(new MetricRegistry(), new DimensionRegistry()),
        );
    }

    public function testUsesPresetMetricsWhenRequestHasNoOverride(): void
    {
        $draft = $this->draftQuery();
        $preset = new AnalysisPreset(
            key: 'allocations_by_month_with_share',
            title: 'Share',
            primaryDimensionKey: 'month',
            metricKeys: ['count', 'percent_of_total'],
        );

        $keys = $this->resolver->resolveMetricKeys(Request::create('/'), $draft, $preset);

        self::assertSame(['count', 'percent_of_total'], $keys);
    }

    public function testNormalizesRequestedMetrics(): void
    {
        $draft = $this->draftQuery();
        $preset = new AnalysisPreset(key: 'x', title: 'X', primaryDimensionKey: 'month');
        $request = Request::create('/', Request::METHOD_GET, [
            'ga_metrics' => ['count', 'percent_of_total', 'evil', 'percent_of_bucket'],
        ]);

        $keys = $this->resolver->resolveMetricKeys($request, $draft, $preset);

        self::assertSame(['count', 'percent_of_total'], $keys);
    }

    public function testPercentOfBucketAllowedWithSeries(): void
    {
        $draft = $this->draftQuery(series: 'urgency');
        $preset = new AnalysisPreset(key: 'x', title: 'X', primaryDimensionKey: 'month', seriesDimensionKey: 'urgency');
        $request = Request::create('/', Request::METHOD_GET, [
            'ga_metrics' => ['count', 'percent_of_bucket'],
        ]);

        $keys = $this->resolver->resolveMetricKeys($request, $draft, $preset);

        self::assertContains('percent_of_bucket', $keys);
    }

    public function testResolveVisualMetricFromQuery(): void
    {
        $request = Request::create('/', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::VISUAL_METRIC => 'percent_of_total',
        ]);

        $visual = $this->resolver->resolveVisualMetricKey(
            $request,
            ['count', 'percent_of_total'],
            'count',
        );

        self::assertSame('percent_of_total', $visual);
    }

    private function draftQuery(?string $series = null): AnalysisQuery
    {
        return new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: $series,
        );
    }
}
