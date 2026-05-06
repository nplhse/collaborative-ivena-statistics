<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum StatisticsFilterScope: string
{
    case Public = 'public';
    case MyHospitals = 'my_hospitals';
    case Hospital = 'hospital';
    case HospitalCohort = 'hospital_cohort';
}
