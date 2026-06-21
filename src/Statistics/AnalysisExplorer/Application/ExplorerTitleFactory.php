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
        $grain = $timeGrain ?? AnalysisDimensionGrain::Month;

        return match ($dimensionKey) {
            AnalysisDimensionKey::Time => $this->translator->trans('stats.analysis_explorer.allocations_over_time'),
            AnalysisDimensionKey::Gender => $grain->isTemporal()
                ? $this->translator->trans('stats.analysis_explorer.allocations_by_gender_over_time')
                : $this->translator->trans('stats.analysis_explorer.allocations_by_gender'),
            AnalysisDimensionKey::Urgency => $grain->isTemporal()
                ? $this->translator->trans('stats.analysis_explorer.allocations_by_urgency_over_time')
                : $this->translator->trans('stats.analysis_explorer.allocations_by_urgency'),
        };
    }

    public function titleForConfig(AnalysisViewConfig $config): string
    {
        return $this->titleFor($config->dimensionKey, $config->timeGrain);
    }
}
