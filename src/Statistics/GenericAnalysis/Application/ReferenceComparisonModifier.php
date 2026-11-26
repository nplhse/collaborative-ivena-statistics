<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Exception\InvalidAnalysisConfigurationException;
use App\Statistics\GenericAnalysis\Domain\ReferenceComparison;

/**
 * Puts the reference comparison series into the series slot of an allocations query (#1346), like
 * HospitalPopulationModifier does for the hospital population groups. A query with reference hospitals and a
 * manual series dimension is invalid. Whether the viewer may use the references is decided before, by the
 * Explorer's comparison policy; this class only shapes the query.
 */
final readonly class ReferenceComparisonModifier
{
    public function supports(AnalysisQuery $query): bool
    {
        return AnalysisDataSource::Allocations === $query->dataSource && [] !== $query->referenceHospitalIds;
    }

    public function validate(AnalysisQuery $query): void
    {
        if (!$this->supports($query)) {
            return;
        }

        $distinct = array_values(array_unique($query->referenceHospitalIds));
        if (\count($distinct) > ReferenceComparison::MAX_REFERENCES) {
            throw InvalidAnalysisConfigurationException::withMessage(sprintf('The reference comparison allows at most %d reference hospitals.', ReferenceComparison::MAX_REFERENCES));
        }

        if (null !== $query->seriesDimensionKey && ReferenceComparison::DIMENSION_KEY !== $query->seriesDimensionKey) {
            throw InvalidAnalysisConfigurationException::withMessage('The reference comparison cannot be combined with a manual series dimension.');
        }

        if (ReferenceComparison::DIMENSION_KEY === $query->primaryDimensionKey) {
            throw InvalidAnalysisConfigurationException::withMessage('The reference comparison is a series, not a primary dimension.');
        }
    }

    public function prepareForExecution(AnalysisQuery $query): AnalysisQuery
    {
        if (!$this->supports($query)) {
            return $query;
        }

        return new AnalysisQuery(
            primaryDimensionKey: $query->primaryDimensionKey,
            scopeCriteria: $query->scopeCriteria,
            periodBounds: $query->periodBounds,
            seriesDimensionKey: ReferenceComparison::DIMENSION_KEY,
            metricKeys: $query->metricKeys,
            visualMetricKey: $query->visualMetricKey,
            filters: $query->filters,
            includeNullBuckets: $query->includeNullBuckets,
            dataSource: $query->dataSource,
            hospitalPopulationMode: $query->hospitalPopulationMode,
            referenceHospitalIds: array_values(array_unique($query->referenceHospitalIds)),
        );
    }
}
