<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class AnalysisTotals
{
    /**
     * @param array<string, int|float|null>                $grand    keyed by explorer metric value
     * @param array<string, array<string, int|float|null>> $byRow    rowKey => metric => value
     * @param array<string, array<string, int|float|null>> $byColumn colKey => metric => value
     */
    public function __construct(
        public array $grand,
        public array $byRow = [],
        public array $byColumn = [],
    ) {
    }

    public function grandFor(AnalysisMetricKey $metricKey): int|float|null
    {
        return $this->grand[$metricKey->value] ?? null;
    }
}
