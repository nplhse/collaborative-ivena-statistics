<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ExplorerDimensionCategory: string
{
    case TimeAndCalendar = 'time_and_calendar';
    case MissionAndAllocation = 'mission_and_allocation';
    case PatientAndDemographics = 'patient_and_demographics';
    case ClinicalCare = 'clinical_care';
    case TransportAndDuration = 'transport_and_duration';
    case HospitalAndGeography = 'hospital_and_geography';
    case HospitalProfile = 'hospital_profile';
    case GeographyAndParticipation = 'geography_and_participation';

    public function labelTranslationKey(): string
    {
        return 'stats.analysis_explorer.dimension_group.'.$this->value;
    }
}
