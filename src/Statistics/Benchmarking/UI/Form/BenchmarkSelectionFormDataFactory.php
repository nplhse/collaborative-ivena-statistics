<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionFormData;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;

final readonly class BenchmarkSelectionFormDataFactory
{
    public function fromFilters(StatisticsFilter $primaryFilter, StatisticsFilter $comparisonFilter): BenchmarkSelectionFormData
    {
        return new BenchmarkSelectionFormData(
            $this->sideFromFilter($primaryFilter),
            $this->sideFromFilter($comparisonFilter),
        );
    }

    private function sideFromFilter(StatisticsFilter $filter): BenchmarkSelectionSideFormData
    {
        [$scopeGroup, $scopeDetail] = $this->scopeGroupFromFilter($filter);
        $now = new \DateTimeImmutable();

        return new BenchmarkSelectionSideFormData(
            $scopeGroup,
            $scopeDetail,
            $filter->period->value,
            $filter->referenceYear ?? (int) $now->format('Y'),
            $filter->referenceQuarter ?? (int) ceil((int) $now->format('n') / 3),
            $filter->referenceMonth ?? (int) $now->format('n'),
        );
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function scopeGroupFromFilter(StatisticsFilter $filter): array
    {
        return match ($filter->scope) {
            StatisticsFilterScope::Public => ['public', null],
            StatisticsFilterScope::State => ['state', null !== $filter->stateId ? (string) $filter->stateId : null],
            StatisticsFilterScope::DispatchArea => ['dispatch_area', null !== $filter->dispatchAreaId ? (string) $filter->dispatchAreaId : null],
            StatisticsFilterScope::HospitalCohort => [
                'hospital_cohort',
                $filter->cohortType?->value(),
            ],
            StatisticsFilterScope::MyHospitals => ['my_hospitals', null],
            StatisticsFilterScope::Hospital => ['my_hospitals', null !== $filter->hospitalId ? (string) $filter->hospitalId : null],
        };
    }
}
