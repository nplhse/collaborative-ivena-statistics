<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Domain\Entity\SavedExplorerView;

final readonly class SavedExplorerViewLoadResult
{
    /**
     * @param array<string, mixed> $state
     * @param list<string>         $warnings
     */
    public function __construct(
        public array $state,
        public array $warnings = [],
        public bool $notFound = false,
        public ?SavedExplorerView $view = null,
        public bool $usedFallback = false,
    ) {
    }
}
