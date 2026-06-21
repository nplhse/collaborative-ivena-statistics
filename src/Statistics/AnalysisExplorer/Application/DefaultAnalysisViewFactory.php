<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DefaultAnalysisViewFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function createDefault(StatisticsFilter $statisticsFilter): AnalysisViewConfig
    {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionGrain: AnalysisDimensionGrain::Month,
            statisticsFilter: $statisticsFilter,
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::Bar,
            ),
            title: $this->translator->trans('stats.analysis_explorer.allocations_over_time'),
        );
    }
}
