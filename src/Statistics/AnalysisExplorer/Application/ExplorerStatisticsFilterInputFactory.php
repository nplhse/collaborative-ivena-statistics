<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;

final readonly class ExplorerStatisticsFilterInputFactory
{
    public function fromSideFormData(BenchmarkSelectionSideFormData $side): StatisticsFilterInput
    {
        $scope = 'public';
        $hospital = '';
        $cohort = '';
        $state = '';
        $dispatchArea = '';

        switch ($side->scopeGroup) {
            case 'public':
                $scope = StatisticsFilterScope::Public->value;
                break;
            case 'state':
                $scope = StatisticsFilterScope::State->value;
                $state = $side->scopeDetail ?? '';
                break;
            case 'dispatch_area':
                $scope = StatisticsFilterScope::DispatchArea->value;
                $dispatchArea = $side->scopeDetail ?? '';
                break;
            case 'hospital_cohort':
                $scope = StatisticsFilterScope::HospitalCohort->value;
                $cohort = $side->scopeDetail ?? '';
                break;
            case 'my_hospitals':
                if ('' !== ($side->scopeDetail ?? '')) {
                    $scope = StatisticsFilterScope::Hospital->value;
                    $hospital = $side->scopeDetail ?? '';
                } else {
                    $scope = StatisticsFilterScope::MyHospitals->value;
                }
                break;
        }

        return new StatisticsFilterInput(
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
