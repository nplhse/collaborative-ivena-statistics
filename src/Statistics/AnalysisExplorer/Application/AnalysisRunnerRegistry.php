<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\AnalysisRunnerInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Exception\UnsupportedAnalysisException;

final readonly class AnalysisRunnerRegistry
{
    public function __construct(
        private AllocationsAnalysisRunner $allocationsAnalysisRunner,
    ) {
    }

    public function run(AnalysisViewConfig $config, AnalysisQuery $query): AnalysisRunResult
    {
        foreach ($this->runners() as $runner) {
            if ($runner->supports($config)) {
                $result = $runner->run($query);

                return new AnalysisRunResult(
                    title: $config->title,
                    metricKey: $result->metricKey,
                    dimensionGrain: $result->dimensionGrain,
                    dataPoints: $result->dataPoints,
                    total: $result->total,
                );
            }
        }

        throw UnsupportedAnalysisException::forDataSource($config->dataSourceKey);
    }

    /**
     * @return list<AnalysisRunnerInterface>
     */
    private function runners(): array
    {
        return [$this->allocationsAnalysisRunner];
    }
}
