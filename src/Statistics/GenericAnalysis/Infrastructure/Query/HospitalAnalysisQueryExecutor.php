<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\GenericAnalysis\Application\Contract\AnalysisQueryExecutorInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;

final readonly class HospitalAnalysisQueryExecutor implements AnalysisQueryExecutorInterface
{
    public function __construct(
        private GenericHospitalAnalysisQuery $hospitalAnalysisQuery,
    ) {
    }

    public function supports(AnalysisDataSource $dataSource): bool
    {
        return AnalysisDataSource::Hospitals === $dataSource;
    }

    public function execute(AnalysisQuery $query): AnalysisResult
    {
        return $this->hospitalAnalysisQuery->execute($query);
    }
}
