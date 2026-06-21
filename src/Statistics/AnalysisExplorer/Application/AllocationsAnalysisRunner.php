<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
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
        $totals = [];

        foreach ($query->metricKeys as $metricKey) {
            $total = 0.0;
            foreach ($rows as $row) {
                $value = $row->valueFor($metricKey);
                if (null !== $value) {
                    $total += (float) $value;
                }
            }
            $totals[$metricKey->value] = AnalysisMetricKey::PercentOfTotal === $metricKey ? round($total, 2) : $total;
        }

        return new AnalysisRunResult(
            title: '',
            metricKeys: $query->metricKeys,
            visualMetricKey: $query->visualMetricKey,
            dimensionKey: $query->dimensionKey,
            timeGrain: $query->timeGrain,
            rows: $rows,
            totals: $totals,
        );
    }
}
