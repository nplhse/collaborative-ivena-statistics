<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum AnalysisDimensionKey: string
{
    case Time = 'time';
    case Gender = 'gender';
    case Urgency = 'urgency';
    case AgeGroup = 'age_group';
    case Department = 'department';
    case HospitalCohort = 'hospital_cohort';
    case Speciality = 'speciality';
    case Occasion = 'occasion';
    case Assignment = 'assignment';
    case Indication = 'indication';
    case SecondaryIndication = 'secondary_indication';
    case Infection = 'infection';
    case Weekday = 'weekday';
    case Hour = 'hour';
    case TransportType = 'transport_type';
    case TransportTimeBucket = 'transport_time_bucket';
    case DayTimeBucket = 'day_time_bucket';
    case ShiftBucket = 'shift_bucket';
    case Resus = 'resus';
    case Cathlab = 'cathlab';
    case Cpr = 'cpr';
    case Ventilation = 'ventilation';
    case Shock = 'shock';
    case WorkAccident = 'workAccident';
    case Pregnancy = 'pregnancy';
    case WithPhysician = 'with_physician';
    case ClinicalResources = 'clinical_resources';
    case ClinicalFeatures = 'clinical_features';
    case Hospital = 'hospital';
    case State = 'state';
    case DispatchArea = 'dispatchArea';
    case HospitalTier = 'hospital_tier';
    case HospitalSize = 'hospital_size';
    case HospitalLocation = 'hospital_location';
    case HospitalState = 'hospital_state';
    case HospitalDispatchArea = 'hospital_dispatch_area';
    case HospitalEntity = 'hospital_entity';
    case HospitalMasterCohort = 'hospital_master_cohort';
    case HospitalPopulationGroup = 'hospital_population_group';

    public function isTemporalPrimary(): bool
    {
        return self::Time === $this;
    }

    public function registryKey(): string
    {
        if ($this->isTemporalPrimary()) {
            throw new \LogicException('The time dimension maps to registry keys via grain.');
        }

        return $this->value;
    }

    public function explorerCategory(AnalysisDataSourceKey $dataSourceKey): ExplorerDimensionCategory
    {
        return match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => match ($this) {
                self::Time,
                self::Weekday,
                self::Hour,
                self::DayTimeBucket,
                self::ShiftBucket => ExplorerDimensionCategory::TimeAndCalendar,
                self::Occasion,
                self::Assignment,
                self::Indication,
                self::SecondaryIndication,
                self::Speciality,
                self::Department,
                self::TransportType,
                self::Urgency => ExplorerDimensionCategory::MissionAndAllocation,
                self::Gender,
                self::AgeGroup,
                self::Infection,
                self::Pregnancy,
                self::WorkAccident => ExplorerDimensionCategory::PatientAndDemographics,
                self::Resus,
                self::Cathlab,
                self::Cpr,
                self::Ventilation,
                self::Shock,
                self::WithPhysician,
                self::ClinicalResources,
                self::ClinicalFeatures => ExplorerDimensionCategory::ClinicalCare,
                self::TransportTimeBucket => ExplorerDimensionCategory::TransportAndDuration,
                self::Hospital,
                self::HospitalCohort,
                self::State,
                self::DispatchArea => ExplorerDimensionCategory::HospitalAndGeography,
                default => throw new \LogicException(sprintf('Dimension "%s" is not part of the allocations explorer catalog.', $this->value)),
            },
            AnalysisDataSourceKey::Hospitals => match ($this) {
                self::HospitalEntity,
                self::HospitalLocation,
                self::HospitalMasterCohort,
                self::HospitalSize,
                self::HospitalTier => ExplorerDimensionCategory::HospitalProfile,
                self::HospitalDispatchArea,
                self::HospitalPopulationGroup,
                self::HospitalState => ExplorerDimensionCategory::GeographyAndParticipation,
                default => throw new \LogicException(sprintf('Dimension "%s" is not part of the hospitals explorer catalog.', $this->value)),
            },
        };
    }

    /**
     * @return list<self>
     */
    public static function allocationsCatalog(): array
    {
        return [
            self::Time,
            self::Gender,
            self::Urgency,
            self::AgeGroup,
            self::Department,
            self::HospitalCohort,
            self::Speciality,
            self::Occasion,
            self::Assignment,
            self::Indication,
            self::SecondaryIndication,
            self::Infection,
            self::Weekday,
            self::Hour,
            self::TransportType,
            self::TransportTimeBucket,
            self::DayTimeBucket,
            self::ShiftBucket,
            self::Resus,
            self::Cathlab,
            self::Cpr,
            self::Ventilation,
            self::Shock,
            self::WorkAccident,
            self::Pregnancy,
            self::WithPhysician,
            self::ClinicalResources,
            self::ClinicalFeatures,
            self::Hospital,
            self::State,
            self::DispatchArea,
        ];
    }

    /**
     * @return list<self>
     */
    public static function hospitalsCatalog(): array
    {
        return [
            self::HospitalMasterCohort,
            self::HospitalTier,
            self::HospitalSize,
            self::HospitalLocation,
            self::HospitalState,
            self::HospitalDispatchArea,
            self::HospitalEntity,
            self::HospitalPopulationGroup,
        ];
    }
}
