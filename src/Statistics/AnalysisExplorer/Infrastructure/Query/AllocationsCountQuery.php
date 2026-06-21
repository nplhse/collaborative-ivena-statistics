<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationQueryMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationResultMapper;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;

final readonly class AllocationsCountQuery
{
    public function __construct(
        private ExplorerAllocationQueryMapper $queryMapper,
        private GenericAllocationAnalysisQuery $genericQuery,
        private ExplorerAllocationResultMapper $resultMapper,
    ) {
    }

    /**
     * @return list<AnalysisResultRow>
     */
    public function execute(AnalysisQuery $query): array
    {
        if ($this->hasEmptyHospitalScope($query)) {
            return [];
        }

        $genericResult = $this->genericQuery->execute($this->queryMapper->map($query));

        return $this->resultMapper->map($genericResult);
    }

    private function hasEmptyHospitalScope(AnalysisQuery $query): bool
    {
        $hospitalIds = $query->scopeCriteria->hospitalIds;

        return \is_array($hospitalIds) && [] === $hospitalIds;
    }
}
