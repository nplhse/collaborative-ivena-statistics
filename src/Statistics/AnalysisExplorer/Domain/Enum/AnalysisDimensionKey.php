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
    case Hospital = 'hospital';
    case State = 'state';
    case DispatchArea = 'dispatchArea';

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
            self::Hospital,
            self::State,
            self::DispatchArea,
        ];
    }
}
