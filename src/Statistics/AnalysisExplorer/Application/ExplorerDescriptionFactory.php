<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerDescriptionFactory
{
    public function __construct(
        private TranslatorInterface $translator,
        private ExplorerTitleFactory $titleFactory,
    ) {
    }

    public function descriptionForConfig(AnalysisViewConfig $config): string
    {
        return $this->descriptionFor(
            $config->dimensionKey,
            $config->timeGrain ?? AnalysisDimensionGrain::Month,
            $config->presentation->chartType,
        );
    }

    public function descriptionFor(
        AnalysisDimensionKey $dimensionKey,
        AnalysisDimensionGrain $grain,
        ChartPresentationType $chartType,
    ): string {
        $key = match ($dimensionKey) {
            AnalysisDimensionKey::Time => match ($grain) {
                AnalysisDimensionGrain::Month => 'stats.analysis_explorer.description.time_month',
                AnalysisDimensionGrain::Year => ChartPresentationType::Line === $chartType
                    ? 'stats.analysis_explorer.description.time_year_line'
                    : 'stats.analysis_explorer.description.time_year',
                AnalysisDimensionGrain::Total => 'stats.analysis_explorer.description.time_total',
            },
            AnalysisDimensionKey::Gender => $grain->isTemporal()
                ? 'stats.analysis_explorer.description.gender_over_time'
                : 'stats.analysis_explorer.description.gender_total',
            AnalysisDimensionKey::Urgency => $grain->isTemporal()
                ? (AnalysisDimensionGrain::Year === $grain && ChartPresentationType::StackedBar === $chartType
                    ? 'stats.analysis_explorer.description.urgency_year_stacked'
                    : 'stats.analysis_explorer.description.urgency_over_time')
                : 'stats.analysis_explorer.description.urgency_total',
        };

        return $this->translator->trans($key, [
            'grain' => $this->grainLabel($grain),
            'chart' => $this->chartLabel($chartType),
            'title' => $this->titleFactory->titleFor($dimensionKey, $grain),
        ]);
    }

    private function grainLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Month => $this->translator->trans('stats.analysis_explorer.description.grain.month'),
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.description.grain.year'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total'),
        };
    }

    private function chartLabel(ChartPresentationType $chartType): string
    {
        return match ($chartType) {
            ChartPresentationType::Bar => $this->translator->trans('stats.analysis_explorer.chart.bar'),
            ChartPresentationType::Line => $this->translator->trans('stats.analysis_explorer.chart.line'),
            ChartPresentationType::GroupedBar => $this->translator->trans('stats.analysis_explorer.chart.grouped_bar'),
            ChartPresentationType::StackedBar => $this->translator->trans('stats.analysis_explorer.chart.stacked_bar'),
        };
    }
}
