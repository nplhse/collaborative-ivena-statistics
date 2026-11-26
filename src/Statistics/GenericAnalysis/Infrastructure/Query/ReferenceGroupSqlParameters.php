<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Query;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Exception\InvalidAnalysisConfigurationException;
use App\Statistics\GenericAnalysis\Domain\ReferenceComparison;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Binds the reference hospital ids when a query groups by the reference comparison series (#1346). The ids are
 * always a bound integer array, never part of the SQL text.
 */
final class ReferenceGroupSqlParameters
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     */
    public static function apply(AnalysisQuery $query, AnalysisDimension $primary, ?AnalysisDimension $series, array &$params, array &$types): void
    {
        if (ReferenceComparison::DIMENSION_KEY !== $primary->key && ReferenceComparison::DIMENSION_KEY !== $series?->key) {
            return;
        }

        if ([] === $query->referenceHospitalIds) {
            throw InvalidAnalysisConfigurationException::withMessage('The reference comparison needs at least one reference hospital.');
        }

        $params[ReferenceComparison::PARAMETER] = $query->referenceHospitalIds;
        $types[ReferenceComparison::PARAMETER] = ArrayParameterType::INTEGER;
    }
}
