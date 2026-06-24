<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\ExplorerAnalysisQueryMapperInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\GenericAnalysis\Application\HospitalPopulationModifier;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery as GenericAnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;

final readonly class ExplorerHospitalQueryMapper implements ExplorerAnalysisQueryMapperInterface
{
    public function __construct(
        private ExplorerMetricKeyMapper $metricKeyMapper,
        private HospitalPopulationModifier $hospitalPopulationModifier,
    ) {
    }

    #[\Override]
    public function supports(AnalysisQuery $query): bool
    {
        return AnalysisDataSourceKey::Hospitals === $query->dataSourceKey;
    }

    #[\Override]
    public function map(AnalysisQuery $query): GenericAnalysisQuery
    {
        $metricKeys = $this->metricKeyMapper->toRegistryKeys($query->metricKeys);
        $visualMetricKey = $query->visualMetricKey->registryKey();
        $populationMode = $this->toGenericPopulationMode($query->hospitalPopulationMode);

        $gaQuery = new GenericAnalysisQuery(
            primaryDimensionKey: $query->rowAxis->toRegistryKey(),
            scopeCriteria: $query->scopeCriteria,
            periodBounds: $query->periodBounds,
            seriesDimensionKey: $query->columnAxis?->toRegistryKey(),
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: $populationMode,
        );

        $this->hospitalPopulationModifier->validate($gaQuery);

        return $this->hospitalPopulationModifier->prepareForExecution($gaQuery);
    }

    private function toGenericPopulationMode(ExplorerHospitalPopulationMode $mode): HospitalPopulationMode
    {
        return match ($mode) {
            ExplorerHospitalPopulationMode::All => HospitalPopulationMode::All,
            ExplorerHospitalPopulationMode::Participating => HospitalPopulationMode::Participating,
            ExplorerHospitalPopulationMode::Compare => HospitalPopulationMode::Compare,
        };
    }
}
