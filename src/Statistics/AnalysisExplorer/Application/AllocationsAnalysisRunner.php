<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\AnalysisRunnerInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\AllocationsCountQuery;

final readonly class AllocationsAnalysisRunner implements AnalysisRunnerInterface
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
        $dataPoints = $this->countQuery->execute($query);
        $total = 0;

        foreach ($dataPoints as $point) {
            $total += $point->value;
        }

        return new AnalysisRunResult(
            title: '',
            metricKey: $query->metricKey,
            dimensionKey: $query->dimensionKey,
            timeGrain: $query->timeGrain,
            dataPoints: $dataPoints,
            total: $total,
        );
    }
}
