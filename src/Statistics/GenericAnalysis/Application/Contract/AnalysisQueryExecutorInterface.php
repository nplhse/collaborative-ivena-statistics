<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Contract;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;

interface AnalysisQueryExecutorInterface
{
    public function supports(AnalysisDataSource $dataSource): bool;

    public function execute(AnalysisQuery $query): AnalysisResult;
}
