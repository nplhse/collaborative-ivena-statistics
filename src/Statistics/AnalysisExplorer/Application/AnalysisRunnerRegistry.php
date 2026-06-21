<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

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
                    metricKeys: $result->metricKeys,
                    visualMetricKey: $result->visualMetricKey,
                    dimensionKey: $result->dimensionKey,
                    timeGrain: $result->timeGrain,
                    rows: $result->rows,
                    totals: $result->totals,
                );
            }
        }

        throw UnsupportedAnalysisException::forDataSource($config->dataSourceKey);
    }

    /**
     * @return list<Contract\AnalysisRunnerInterface>
     */
    private function runners(): array
    {
        return [$this->allocationsAnalysisRunner];
    }
}
