<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\GenericAnalysis\Application\Contract\AnalysisQueryExecutorInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;

final readonly class AllocationAnalysisQueryExecutor implements AnalysisQueryExecutorInterface
{
    public function __construct(
        private GenericAllocationAnalysisQuery $allocationAnalysisQuery,
    ) {
    }

    public function supports(AnalysisDataSource $dataSource): bool
    {
        return AnalysisDataSource::Allocations === $dataSource;
    }

    public function execute(AnalysisQuery $query): AnalysisResult
    {
        return $this->allocationAnalysisQuery->execute($query);
    }
}
