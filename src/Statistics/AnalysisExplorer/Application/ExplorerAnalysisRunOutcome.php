<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;

final readonly class ExplorerAnalysisRunOutcome
{
    /**
     * @param array<string, mixed>|null $normalizedConfigState
     */
    public function __construct(
        public ?AnalysisRunResult $result,
        public ?string $emptyReason,
        public ?string $configWarning,
        public ?array $normalizedConfigState = null,
    ) {
    }
}
