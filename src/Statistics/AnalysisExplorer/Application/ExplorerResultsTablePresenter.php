<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableRow;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerResultsTablePresenter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function create(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $rows = [];
        foreach ($result->dataPoints as $point) {
            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $point->label,
                value: $point->value,
            );
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->dimensionLabel($viewConfig->dimensionKey, $viewConfig->timeGrain),
            metricLabel: $this->metricLabel($viewConfig->metricKey),
            rows: $rows,
            total: $result->total,
        );
    }

    private function dimensionLabel(AnalysisDimensionKey $dimensionKey, ?AnalysisDimensionGrain $timeGrain): string
    {
        return match ($dimensionKey) {
            AnalysisDimensionKey::Time => match ($timeGrain) {
                AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year'),
                default => $this->translator->trans('stats.analysis_explorer.dimension.month'),
            },
            AnalysisDimensionKey::Gender => $this->translator->trans('stats.analysis_explorer.dimension.gender'),
            AnalysisDimensionKey::Urgency => $this->translator->trans('stats.analysis_explorer.dimension.urgency'),
        };
    }

    private function metricLabel(AnalysisMetricKey $metricKey): string
    {
        return match ($metricKey) {
            AnalysisMetricKey::AllocationCount => $this->translator->trans('stats.analysis_explorer.metric.allocation_count'),
        };
    }
}
