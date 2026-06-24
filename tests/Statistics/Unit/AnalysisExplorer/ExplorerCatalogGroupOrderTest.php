<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerDimensionCatalog;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCatalog;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;

final class ExplorerCatalogGroupOrderTest extends TestCase
{
    public function testAllocationsMetricGroupOrder(): void
    {
        $catalog = new ExplorerMetricCatalog(new MetricRegistry());

        self::assertSame(
            [
                'stats.analysis_explorer.metric_group.counts',
                'stats.analysis_explorer.metric_group.clinical_rates',
                'stats.analysis_explorer.metric_group.shares',
                'stats.analysis_explorer.metric_group.transport_times',
            ],
            $catalog->metricGroupOrderFor(AnalysisDataSourceKey::Allocations),
        );
    }

    public function testHospitalsMetricGroupOrder(): void
    {
        $catalog = new ExplorerMetricCatalog(new MetricRegistry());

        self::assertSame(
            [
                'stats.analysis_explorer.metric_group.counts',
                'stats.analysis_explorer.metric_group.beds',
                'stats.analysis_explorer.metric_group.allocations',
                'stats.analysis_explorer.metric_group.transport_times',
            ],
            $catalog->metricGroupOrderFor(AnalysisDataSourceKey::Hospitals),
        );
    }

    public function testDimensionCategoryGroupOrderMatchesCategoryLabels(): void
    {
        $catalog = new ExplorerDimensionCatalog();

        self::assertSame(
            array_map(
                static fn (\App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerDimensionCategory $category): string => $category->labelTranslationKey(),
                $catalog->categoryOrderFor(AnalysisDataSourceKey::Allocations),
            ),
            $catalog->categoryGroupOrderFor(AnalysisDataSourceKey::Allocations),
        );
    }
}
