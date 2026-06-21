<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerTitleFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function titleFor(AnalysisDimensionKey $dimensionKey, ?AnalysisDimensionGrain $timeGrain = null): string
    {
        if ($dimensionKey->isTemporalPrimary()) {
            return $this->translator->trans('stats.analysis_explorer.allocations_over_time');
        }

        $dimensionLabel = $this->dimensionLabel($dimensionKey);

        if ($timeGrain instanceof AnalysisDimensionGrain && $timeGrain->isTemporal()) {
            return $this->translator->trans('stats.analysis_explorer.allocations_by_dimension_over_time', [
                'dimension' => $dimensionLabel,
            ]);
        }

        return $this->translator->trans('stats.analysis_explorer.allocations_by_dimension', [
            'dimension' => $dimensionLabel,
        ]);
    }

    public function titleForConfig(AnalysisViewConfig $config): string
    {
        return $this->titleFor($config->dimensionKey, $config->timeGrain);
    }

    private function dimensionLabel(AnalysisDimensionKey $dimensionKey): string
    {
        return $this->translator->trans('stats.analysis_explorer.dimension.'.$dimensionKey->value);
    }
}
