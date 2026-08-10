<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;

interface ExplorerAnalysisSummaryFilterLabelsInterface
{
    /**
     * @return list<array{label: string, value: string}>
     */
    public function present(AnalysisViewConfig $config, ?string $locale = null): array;
}
