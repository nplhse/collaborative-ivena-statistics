<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerTitleFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function titleForAxes(AnalysisAxisRef $rowAxis, ?AnalysisAxisRef $columnAxis): string
    {
        if (!$columnAxis instanceof AnalysisAxisRef) {
            if ($rowAxis->dimensionKey->isTemporalPrimary()) {
                return $this->translator->trans('stats.analysis_explorer.allocations_over_time', [], 'statistics');
            }

            return $this->translator->trans('stats.analysis_explorer.allocations_by_dimension', [
                'dimension' => $this->dimensionLabel($rowAxis->dimensionKey),
            ], 'statistics');
        }

        if ($rowAxis->dimensionKey->isTemporalPrimary()) {
            return $this->translator->trans('stats.analysis_explorer.allocations_by_dimension_over_time', [
                'dimension' => $this->dimensionLabel($columnAxis->dimensionKey),
            ], 'statistics');
        }

        if ($columnAxis->dimensionKey->isTemporalPrimary()) {
            return $this->translator->trans('stats.analysis_explorer.allocations_by_dimension_by_temporal', [
                'dimension' => $this->dimensionLabel($rowAxis->dimensionKey),
                'temporal' => $this->temporalLabel($columnAxis->resolvedGrain()),
            ], 'statistics');
        }

        return $this->translator->trans('stats.analysis_explorer.allocations_cross_tab', [
            'rows' => $this->dimensionLabel($rowAxis->dimensionKey),
            'columns' => $this->dimensionLabel($columnAxis->dimensionKey),
        ], 'statistics');
    }

    public function titleForConfig(AnalysisViewConfig $config): string
    {
        return $this->titleForAxes($config->rowAxis, $config->columnAxis);
    }

    private function dimensionLabel(AnalysisDimensionKey $dimensionKey): string
    {
        return $this->translator->trans('stats.analysis_explorer.dimension.'.$dimensionKey->value, [], 'statistics');
    }

    private function temporalLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year', [], 'statistics'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter', [], 'statistics'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week', [], 'statistics'),
            default => $this->translator->trans('stats.analysis_explorer.dimension.month', [], 'statistics'),
        };
    }
}
