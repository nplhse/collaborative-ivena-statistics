<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;

final readonly class AnalysisExecutionResult
{
    /**
     * @param array<string, mixed>            $chartSpecs
     * @param array<string, string|int|float> $warningParameters
     */
    public function __construct(
        public AnalysisViewConfig $config,
        public AnalysisRunResult $result,
        public array $chartSpecs,
        public string $defaultChartType,
        public bool $hasChart,
        public ExplorerResultsTableViewModel $table,
        public ?string $emptyReason,
        public bool $configRewritten,
        public ?string $warningKey = null,
        public array $warningParameters = [],
    ) {
    }
}
