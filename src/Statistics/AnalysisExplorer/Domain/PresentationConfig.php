<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;

final readonly class PresentationConfig
{
    public function __construct(
        public ChartPresentationType $chartType,
    ) {
    }
}
