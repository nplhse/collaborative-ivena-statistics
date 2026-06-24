<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerConfigPreviewFactory;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use PHPUnit\Framework\TestCase;

final class ExplorerConfigPreviewFactoryTest extends TestCase
{
    public function testMergesAdditionalTableMetricsForHospitals(): void
    {
        $capabilities = new DataSourceCapabilities(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            dimensions: AnalysisDimensionKey::hospitalsCatalog(),
            primaryMetrics: AnalysisMetricKey::primaryHospitalMetricChoices(),
            metrics: AnalysisMetricKey::enabledHospitalsCatalog(),
            timeGrains: [],
            chartTypes: [ChartPresentationType::Bar],
            defaultDimension: AnalysisDimensionKey::HospitalTier,
            defaultMetric: AnalysisMetricKey::HospitalCount,
            defaultTimeGrain: AnalysisDimensionGrain::Total,
            defaultChartType: ChartPresentationType::Bar,
        );

        $formData = new ExplorerEditFormData(
            dataSource: 'hospitals',
            rowDimension: 'hospital_tier',
            rowGrain: 'total',
            metric: 'hospital_count',
            additionalTableMetrics: ['avg_beds'],
        );

        $config = new ExplorerConfigPreviewFactory()->fromFormData(
            $capabilities,
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            null,
            AnalysisMetricKey::HospitalCount,
            $formData,
        );

        self::assertSame(
            [AnalysisMetricKey::HospitalCount, AnalysisMetricKey::AvgBeds],
            $config->metricKeys,
        );
    }

    public function testDistributionProfileIgnoresAdditionalTableMetrics(): void
    {
        $capabilities = new DataSourceCapabilities(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            dimensions: AnalysisDimensionKey::hospitalsCatalog(),
            primaryMetrics: AnalysisMetricKey::primaryHospitalMetricChoices(),
            metrics: AnalysisMetricKey::enabledHospitalsCatalog(),
            timeGrains: [],
            chartTypes: [ChartPresentationType::BoxPlot],
            defaultDimension: AnalysisDimensionKey::HospitalTier,
            defaultMetric: AnalysisMetricKey::BedsDistribution,
            defaultTimeGrain: AnalysisDimensionGrain::Total,
            defaultChartType: ChartPresentationType::BoxPlot,
        );

        $formData = new ExplorerEditFormData(
            dataSource: 'hospitals',
            rowDimension: 'hospital_tier',
            rowGrain: 'total',
            metric: 'beds_distribution',
            additionalTableMetrics: ['avg_beds'],
        );

        $config = new ExplorerConfigPreviewFactory()->fromFormData(
            $capabilities,
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            null,
            AnalysisMetricKey::BedsDistribution,
            $formData,
        );

        self::assertSame([AnalysisMetricKey::BedsDistribution], $config->metricKeys);
    }
}
