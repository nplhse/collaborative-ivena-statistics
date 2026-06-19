<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\Contract\AnalysisQueryModifierInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Domain\Exception\InvalidAnalysisConfigurationException;
use App\Statistics\GenericAnalysis\Domain\HospitalAnalysisConstants;

final readonly class HospitalPopulationModifier implements AnalysisQueryModifierInterface
{
    public function supports(AnalysisDataSource $dataSource): bool
    {
        return AnalysisDataSource::Hospitals === $dataSource;
    }

    public function validate(AnalysisQuery $query): void
    {
        if (!$this->supports($query->dataSource)) {
            return;
        }

        if (HospitalPopulationMode::Compare === $query->hospitalPopulationMode
            && null !== $query->seriesDimensionKey
            && HospitalAnalysisConstants::POPULATION_GROUP_DIMENSION_KEY !== $query->seriesDimensionKey) {
            throw InvalidAnalysisConfigurationException::withMessage('Compare population mode cannot be combined with a manual series dimension.');
        }
    }

    public function prepareForExecution(AnalysisQuery $query): AnalysisQuery
    {
        if (!$this->supports($query->dataSource)) {
            return $query;
        }

        if (HospitalPopulationMode::Compare !== $query->hospitalPopulationMode) {
            return $query;
        }

        return new AnalysisQuery(
            primaryDimensionKey: $query->primaryDimensionKey,
            scopeCriteria: $query->scopeCriteria,
            periodBounds: $query->periodBounds,
            seriesDimensionKey: HospitalAnalysisConstants::POPULATION_GROUP_DIMENSION_KEY,
            metricKeys: $query->metricKeys,
            visualMetricKey: $query->visualMetricKey,
            filters: $query->filters,
            includeNullBuckets: $query->includeNullBuckets,
            seriesMode: $query->seriesMode,
            chartType: $query->chartType,
            displayMode: $query->displayMode,
            chartMetricKeys: $query->chartMetricKeys,
            configVersion: $query->configVersion,
            dataSource: $query->dataSource,
            hospitalPopulationMode: $query->hospitalPopulationMode,
        );
    }
}
