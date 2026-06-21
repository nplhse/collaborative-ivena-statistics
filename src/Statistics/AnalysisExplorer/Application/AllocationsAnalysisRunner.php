<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\AllocationsCountQuery;

final readonly class AllocationsAnalysisRunner implements Contract\AnalysisRunnerInterface
{
    public function __construct(
        private AllocationsCountQuery $countQuery,
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
    ) {
    }

    #[\Override]
    public function supports(AnalysisViewConfig $config): bool
    {
        return $this->capabilitiesProvider->capabilities()->supports($config);
    }

    #[\Override]
    public function run(AnalysisQuery $query): AnalysisRunResult
    {
        $rows = $this->countQuery->execute($query);
        $total = 0;

        foreach ($rows as $row) {
            $total += $row->value;
        }

        return new AnalysisRunResult(
            title: '',
            metricKey: $query->metricKey,
            dimensionKey: $query->dimensionKey,
            timeGrain: $query->timeGrain,
            rows: $rows,
            total: $total,
        );
    }
}
