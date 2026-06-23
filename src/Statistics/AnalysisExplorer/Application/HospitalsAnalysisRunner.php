<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\AnalysisRunnerInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Infrastructure\Query\HospitalsCountQuery;

final readonly class HospitalsAnalysisRunner implements AnalysisRunnerInterface
{
    public function __construct(
        private HospitalsCountQuery $countQuery,
        private HospitalsCapabilitiesProvider $capabilitiesProvider,
        private AnalysisTotalsCalculator $totalsCalculator,
    ) {
    }

    #[\Override]
    public function supports(AnalysisViewConfig $config): bool
    {
        return AnalysisDataSourceKey::Hospitals === $config->dataSourceKey
            && $this->capabilitiesProvider->capabilitiesFor(null, $config->statisticsFilter)->supports($config);
    }

    #[\Override]
    public function run(AnalysisQuery $query): AnalysisRunResult
    {
        $rows = $this->countQuery->execute($query);

        return new AnalysisRunResult(
            title: '',
            metricKeys: $query->metricKeys,
            visualMetricKey: $query->visualMetricKey,
            rowAxis: $query->rowAxis,
            columnAxis: $query->columnAxis,
            rows: $rows,
            totals: $this->totalsCalculator->calculate($rows, $query->metricKeys),
        );
    }
}
