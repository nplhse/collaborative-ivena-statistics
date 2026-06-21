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
        if ($dimensionKey->isTemporalPrimary()) {
            return $this->translator->trans('stats.analysis_explorer.description.temporal_primary', [
                'grain' => $this->grainLabel($grain),
            ]);
        }

        if ($grain->isTemporal()) {
            return $this->translator->trans('stats.analysis_explorer.description.breakdown_over_time', [
                'dimension' => $this->titleFactory->titleFor($dimensionKey, AnalysisDimensionGrain::Total),
                'grain' => $this->grainLabel($grain),
                'chart' => $this->chartLabel($chartType),
            ]);
        }

        return $this->translator->trans('stats.analysis_explorer.description.breakdown_total', [
            'dimension' => $this->titleFactory->titleFor($dimensionKey, AnalysisDimensionGrain::Total),
        ]);
    }

    private function grainLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Month => $this->translator->trans('stats.analysis_explorer.description.grain.month'),
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.description.grain.year'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total'),
        };
    }

    private function chartLabel(ChartPresentationType $chartType): string
    {
        return $this->translator->trans('stats.analysis_explorer.chart.'.$chartType->value);
    }
}
