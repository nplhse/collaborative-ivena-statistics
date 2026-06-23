<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;

final readonly class ExplorerStatisticsFilterInputFactory
{
    public function fromSideFormData(StatisticsScopePeriodFormData $side): \App\Statistics\Application\DTO\StatisticsFilterInput
    {
        $scope = 'public';
        $hospital = '';
        $cohort = '';
        $state = '';
        $dispatchArea = '';

        switch ($side->scopeGroup) {
            case 'public':
                $scope = \App\Statistics\Application\DTO\StatisticsFilterScope::Public->value;
                break;
            case 'state':
                $scope = \App\Statistics\Application\DTO\StatisticsFilterScope::State->value;
                $state = $side->scopeDetail ?? '';
                break;
            case 'dispatch_area':
                $scope = \App\Statistics\Application\DTO\StatisticsFilterScope::DispatchArea->value;
                $dispatchArea = $side->scopeDetail ?? '';
                break;
            case 'hospital_cohort':
                $scope = \App\Statistics\Application\DTO\StatisticsFilterScope::HospitalCohort->value;
                $cohort = $side->scopeDetail ?? '';
                break;
            case 'my_hospitals':
                if ('' !== ($side->scopeDetail ?? '')) {
                    $scope = \App\Statistics\Application\DTO\StatisticsFilterScope::Hospital->value;
                    $hospital = $side->scopeDetail ?? '';
                } else {
                    $scope = \App\Statistics\Application\DTO\StatisticsFilterScope::MyHospitals->value;
                }
                break;
        }

        return new \App\Statistics\Application\DTO\StatisticsFilterInput(
            scope: $scope,
            hospital: $hospital,
            cohort: $cohort,
            state: $state,
            dispatchArea: $dispatchArea,
            period: $side->period,
            year: $side->periodYear,
            month: $side->periodMonth,
            quarter: $side->periodQuarter,
            hasScopeQueryParameter: true,
        );
    }
}
