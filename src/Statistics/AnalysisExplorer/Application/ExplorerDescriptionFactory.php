<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
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
        return $this->descriptionForAxes(
            $config->rowAxis,
            $config->columnAxis,
            $config->presentation->chartType,
        );
    }

    public function descriptionForAxes(
        AnalysisAxisRef $rowAxis,
        ?AnalysisAxisRef $columnAxis,
        ChartPresentationType $chartType,
    ): string {
        if (!$columnAxis instanceof AnalysisAxisRef && $rowAxis->dimensionKey->isTemporalPrimary()) {
            return $this->translator->trans('stats.analysis_explorer.description.temporal_primary', [
                'grain' => $this->grainLabel($rowAxis->resolvedGrain()),
            ], 'statistics');
        }

        if ($columnAxis instanceof AnalysisAxisRef && $rowAxis->dimensionKey->isTemporalPrimary()) {
            return $this->translator->trans('stats.analysis_explorer.description.breakdown_over_time', [
                'dimension' => $this->titleFactory->titleForAxes(
                    AnalysisAxisRef::breakdown($columnAxis->dimensionKey),
                    null,
                ),
                'grain' => $this->grainLabel($rowAxis->resolvedGrain()),
                'chart' => $this->chartLabel($chartType),
            ], 'statistics');
        }

        if ($columnAxis instanceof AnalysisAxisRef && $columnAxis->dimensionKey->isTemporalPrimary()) {
            return $this->translator->trans('stats.analysis_explorer.description.breakdown_by_temporal', [
                'dimension' => $this->titleFactory->titleForAxes($rowAxis, null),
                'temporal' => $this->grainLabel($columnAxis->resolvedGrain()),
            ], 'statistics');
        }

        return $this->translator->trans('stats.analysis_explorer.description.breakdown_total', [
            'dimension' => $this->titleFactory->titleForAxes($rowAxis, null),
        ], 'statistics');
    }

    private function grainLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Month => $this->translator->trans('stats.analysis_explorer.description.grain.month', [], 'statistics'),
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.description.grain.year', [], 'statistics'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter', [], 'statistics'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week', [], 'statistics'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total', [], 'statistics'),
        };
    }

    private function chartLabel(ChartPresentationType $chartType): string
    {
        return $this->translator->trans('stats.analysis_explorer.chart.'.$chartType->value, [], 'statistics');
    }
}
