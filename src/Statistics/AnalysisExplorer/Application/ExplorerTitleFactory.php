<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerTitleFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function titleFor(AnalysisDimensionKey $dimensionKey): string
    {
        return match ($dimensionKey) {
            AnalysisDimensionKey::Time => $this->translator->trans('stats.analysis_explorer.allocations_over_time'),
            AnalysisDimensionKey::Gender => $this->translator->trans('stats.analysis_explorer.allocations_by_gender'),
            AnalysisDimensionKey::Urgency => $this->translator->trans('stats.analysis_explorer.allocations_by_urgency'),
        };
    }
}
