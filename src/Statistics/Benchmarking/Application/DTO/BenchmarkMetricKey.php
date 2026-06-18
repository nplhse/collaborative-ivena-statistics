<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

enum BenchmarkMetricKey: string
{
    case Total = 'total';
    case CasesPerDay = 'cases_per_day';
    case WithPhysician = 'with_physician';
    case Resus = 'resus';
    case Cathlab = 'cathlab';
    case Cpr = 'cpr';
    case Ventilated = 'ventilated';
    case Shock = 'shock';
    case Infectious = 'infectious';
    case UrgencyEmergency = 'urgency_emergency';
    case Age80Plus = 'age_80_plus';
    case MedianAge = 'median_age';
    case NightDaytime = 'night_daytime';
    case Weekend = 'weekend';
    case MedianTransport = 'median_transport';
    case MeanTransport = 'mean_transport';
    case Gender = 'gender';
    case AgeGroups = 'age_groups';
    case TransportTimes = 'transport_times';
    case TransportType = 'transport_type';
    case DayTimeBuckets = 'day_time_buckets';
    case ShiftBuckets = 'shift_buckets';
    case Urgency = 'urgency';
    case IndicationMix = 'indication_mix';
    case ResourceProfile = 'resource_profile';
    case ClinicalFeatures = 'clinical_features';
}
