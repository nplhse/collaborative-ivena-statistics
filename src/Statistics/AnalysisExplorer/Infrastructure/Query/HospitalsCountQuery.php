<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Infrastructure\Query;

use App\Statistics\AnalysisExplorer\Application\ExplorerHospitalAnalysisExecutor;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;

final readonly class HospitalsCountQuery
{
    public function __construct(
        private ExplorerHospitalAnalysisExecutor $executor,
    ) {
    }

    /**
     * @return list<\App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow>
     */
    public function execute(AnalysisQuery $query): array
    {
        return $this->executor->execute($query);
    }
}
