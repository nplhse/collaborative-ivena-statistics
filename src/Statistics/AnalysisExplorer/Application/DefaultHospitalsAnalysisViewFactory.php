<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class DefaultHospitalsAnalysisViewFactory
{
    public function __construct(
        private ExplorerTitleFactory $titleFactory,
    ) {
    }

    public function createDefault(StatisticsFilter $statisticsFilter): AnalysisViewConfig
    {
        $rowAxis = AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalMasterCohort);

        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: $rowAxis,
            columnAxis: null,
            statisticsFilter: $statisticsFilter,
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::Bar,
            ),
            title: $this->titleFactory->titleForAxes($rowAxis, null),
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Participating,
        );
    }
}
