<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnalysisViewRegistryTest extends KernelTestCase
{
    private AnalysisViewRegistry $viewRegistry;

    private DimensionRegistry $dimensionRegistry;

    private MetricRegistry $metricRegistry;

    private AnalysisPresetRegistry $presetRegistry;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->viewRegistry = $container->get(AnalysisViewRegistry::class);
        $this->dimensionRegistry = $container->get(DimensionRegistry::class);
        $this->metricRegistry = $container->get(MetricRegistry::class);
        $this->presetRegistry = $container->get(AnalysisPresetRegistry::class);
    }

    public function testAllViewsUseValidDimensionsAndMetrics(): void
    {
        foreach ($this->viewRegistry->all() as $view) {
            self::assertTrue($this->dimensionRegistry->has($view->primaryDimensionKey));
            if (null !== $view->secondaryDimensionKey) {
                self::assertTrue($this->dimensionRegistry->has($view->secondaryDimensionKey));
            }
            foreach ($view->metricKeys as $metricKey) {
                self::assertTrue($this->metricRegistry->has($metricKey));
            }
        }
    }

    public function testMigratedViewsMatchPresetConfiguration(): void
    {
        foreach ($this->viewRegistry->all() as $view) {
            $presetKey = $view->legacyPresetKey ?? $view->key;
            self::assertTrue($this->presetRegistry->has($presetKey));
            $preset = $this->presetRegistry->get($presetKey);

            self::assertSame($preset->primaryDimensionKey, $view->primaryDimensionKey);
            self::assertSame($preset->seriesDimensionKey, $view->secondaryDimensionKey);
            self::assertSame($preset->includeNullBuckets, $view->includeNullBuckets);
            self::assertSame($preset->metricKeys, $view->metricKeys);
            self::assertSame($preset->visualMetricKey, $view->visualMetricKey);
        }
    }

    public function testByCategoryAndTag(): void
    {
        $timeViews = $this->viewRegistry->byCategory(AnalysisViewCategory::TimeAndTrends);
        self::assertNotEmpty($timeViews);

        $resusViews = $this->viewRegistry->byTag('resus');
        self::assertNotEmpty($resusViews);
        foreach ($resusViews as $view) {
            self::assertContains('resus', $view->tags);
        }

        $rateViews = $this->viewRegistry->byTag('metric:rate');
        self::assertNotEmpty($rateViews);
        foreach ($rateViews as $view) {
            self::assertContains('metric:rate', $view->tags);
        }
    }

    public function testFeaturedViewsMatchExpansionPlan(): void
    {
        $featured = $this->viewRegistry->featured();
        self::assertCount(6, $featured);

        $featuredKeys = array_map(static fn ($view) => $view->key, $featured);
        self::assertSame([
            'allocations_by_month',
            'urgency_by_month',
            'gender_distribution_by_urgency',
            'resus_rate_by_hour',
            'transport_time_by_urgency',
            'with_physician_rate_by_month',
        ], $featuredKeys);
    }
}
