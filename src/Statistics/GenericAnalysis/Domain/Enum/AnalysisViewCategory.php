<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum AnalysisViewCategory: string
{
    case TimeAndTrends = 'time_and_trends';
    case Patients = 'patients';
    case Clinical = 'clinical';
    case Operations = 'operations';
    case Hospitals = 'hospitals';
    case Benchmarking = 'benchmarking';
}
