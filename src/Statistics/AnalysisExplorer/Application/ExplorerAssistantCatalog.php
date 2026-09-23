<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;

final class ExplorerAssistantCatalog
{
    /**
     * @return list<AnalysisDimensionKey>
     */
    public static function characteristics(): array
    {
        return [
            AnalysisDimensionKey::Urgency,
            AnalysisDimensionKey::AgeGroup,
            AnalysisDimensionKey::Gender,
            AnalysisDimensionKey::Department,
            AnalysisDimensionKey::Weekday,
            AnalysisDimensionKey::Indication,
            AnalysisDimensionKey::Assignment,
            AnalysisDimensionKey::Speciality,
        ];
    }

    /**
     * @return list<AnalysisDimensionKey>
     */
    public static function toplistSuggestions(): array
    {
        return [
            AnalysisDimensionKey::Indication,
            AnalysisDimensionKey::Department,
            AnalysisDimensionKey::Assignment,
        ];
    }

    /**
     * @return list<AnalysisDimensionKey>
     */
    public static function hospitalToplistSuggestions(): array
    {
        return [
            AnalysisDimensionKey::HospitalTier,
            AnalysisDimensionKey::HospitalSize,
            AnalysisDimensionKey::HospitalLocation,
            AnalysisDimensionKey::HospitalState,
        ];
    }

    /**
     * @return list<AnalysisDimensionKey>
     */
    public static function matrixDimensions(): array
    {
        return [
            AnalysisDimensionKey::Weekday,
            AnalysisDimensionKey::Hour,
            AnalysisDimensionKey::Department,
            AnalysisDimensionKey::Urgency,
            AnalysisDimensionKey::AgeGroup,
            AnalysisDimensionKey::Gender,
            AnalysisDimensionKey::Indication,
            AnalysisDimensionKey::Assignment,
        ];
    }

    /**
     * @return list<array{AnalysisDimensionKey, AnalysisDimensionKey}>
     */
    public static function matrixSuggestions(): array
    {
        return [
            [AnalysisDimensionKey::Weekday, AnalysisDimensionKey::Hour],
            [AnalysisDimensionKey::Department, AnalysisDimensionKey::Urgency],
            [AnalysisDimensionKey::AgeGroup, AnalysisDimensionKey::Gender],
            [AnalysisDimensionKey::Indication, AnalysisDimensionKey::Urgency],
        ];
    }

    /**
     * @return list<array{AnalysisDimensionKey, AnalysisDimensionKey}>
     */
    public static function hospitalMatrixSuggestions(): array
    {
        return [
            [AnalysisDimensionKey::HospitalLocation, AnalysisDimensionKey::HospitalTier],
            [AnalysisDimensionKey::HospitalSize, AnalysisDimensionKey::HospitalTier],
            [AnalysisDimensionKey::HospitalLocation, AnalysisDimensionKey::HospitalSize],
            [AnalysisDimensionKey::HospitalState, AnalysisDimensionKey::HospitalTier],
            [AnalysisDimensionKey::HospitalState, AnalysisDimensionKey::HospitalSize],
            [AnalysisDimensionKey::HospitalPopulationGroup, AnalysisDimensionKey::HospitalTier],
            [AnalysisDimensionKey::HospitalMasterCohort, AnalysisDimensionKey::HospitalTier],
            [AnalysisDimensionKey::HospitalDispatchArea, AnalysisDimensionKey::HospitalTier],
        ];
    }

    /**
     * @return list<AnalysisDimensionGrain>
     */
    public static function timeGrains(): array
    {
        return [
            AnalysisDimensionGrain::Day,
            AnalysisDimensionGrain::Week,
            AnalysisDimensionGrain::Month,
            AnalysisDimensionGrain::Quarter,
            AnalysisDimensionGrain::Year,
        ];
    }
}
