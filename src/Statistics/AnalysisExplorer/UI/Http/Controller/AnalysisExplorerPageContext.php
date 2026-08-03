<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class AnalysisExplorerPageContext
{
    /**
     * @param array<string, mixed> $contextControls
     */
    public function __construct(
        public StatisticsFilter $filter,
        public mixed $dataQualityReport,
        public bool $isLoggedIn,
        public array $contextControls = [],
    ) {
    }
}
