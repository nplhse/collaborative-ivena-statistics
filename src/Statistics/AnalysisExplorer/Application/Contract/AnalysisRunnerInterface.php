<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\Contract;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;

interface AnalysisRunnerInterface
{
    public function supports(AnalysisViewConfig $config): bool;

    public function run(AnalysisQuery $query): AnalysisRunResult;
}
