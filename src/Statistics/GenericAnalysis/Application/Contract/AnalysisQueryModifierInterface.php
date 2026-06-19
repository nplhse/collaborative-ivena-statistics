<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Contract;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;

interface AnalysisQueryModifierInterface
{
    public function supports(AnalysisDataSource $dataSource): bool;

    public function validate(AnalysisQuery $query): void;

    public function prepareForExecution(AnalysisQuery $query): AnalysisQuery;
}
