<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationAnalysisExecutor;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;

/**
 * Thin facade kept for runner compatibility.
 */
final readonly class AllocationsCountQuery
{
    public function __construct(
        private ExplorerAllocationAnalysisExecutor $executor,
    ) {
    }

    /**
     * @return list<AnalysisResultRow>
     */
    public function execute(AnalysisQuery $query): array
    {
        return $this->executor->execute($query);
    }
}
