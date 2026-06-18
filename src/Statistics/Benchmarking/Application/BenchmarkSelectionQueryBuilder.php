<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionFormData;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;

final readonly class BenchmarkSelectionQueryBuilder
{
    /** @var list<string> */
    public const array SELECTION_QUERY_KEYS = [
        StatisticsQueryKeys::SCOPE,
        StatisticsQueryKeys::HOSPITAL,
        StatisticsQueryKeys::COHORT,
        StatisticsQueryKeys::STATE,
        StatisticsQueryKeys::DISPATCH_AREA,
        StatisticsQueryKeys::PERIOD,
        StatisticsQueryKeys::YEAR,
        StatisticsQueryKeys::MONTH,
        StatisticsQueryKeys::QUARTER,
        StatisticsQueryKeys::COMPARISON_SCOPE,
        StatisticsQueryKeys::COMPARISON_HOSPITAL,
        StatisticsQueryKeys::COMPARISON_COHORT,
        StatisticsQueryKeys::COMPARISON_STATE,
        StatisticsQueryKeys::COMPARISON_DISPATCH_AREA,
        StatisticsQueryKeys::COMPARISON_PERIOD,
        StatisticsQueryKeys::COMPARISON_YEAR,
        StatisticsQueryKeys::COMPARISON_MONTH,
        StatisticsQueryKeys::COMPARISON_QUARTER,
    ];

    /**
     * @param array<string, bool|float|int|string> $preservedQuery
     *
     * @return array<string, bool|float|int|string>
     */
    public function build(BenchmarkSelectionFormData $data, array $preservedQuery): array
    {
        $query = $preservedQuery;
        foreach (self::SELECTION_QUERY_KEYS as $key) {
            unset($query[$key]);
        }

        return array_merge(
            $query,
            $this->sideQueryParams($data->primary, false),
            $this->sideQueryParams($data->comparison, true),
        );
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    private function sideQueryParams(BenchmarkSelectionSideFormData $side, bool $isComparison): array
    {
        $params = array_merge(
            $this->scopeParams($side, $isComparison),
            $this->periodParams($side, $isComparison),
        );

        return array_filter(
            $params,
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    private function scopeParams(BenchmarkSelectionSideFormData $side, bool $isComparison): array
    {
        $scopeKey = $isComparison ? StatisticsQueryKeys::COMPARISON_SCOPE : StatisticsQueryKeys::SCOPE;
        $hospitalKey = $isComparison ? StatisticsQueryKeys::COMPARISON_HOSPITAL : StatisticsQueryKeys::HOSPITAL;
        $cohortKey = $isComparison ? StatisticsQueryKeys::COMPARISON_COHORT : StatisticsQueryKeys::COHORT;
        $stateKey = $isComparison ? StatisticsQueryKeys::COMPARISON_STATE : StatisticsQueryKeys::STATE;
        $dispatchKey = $isComparison ? StatisticsQueryKeys::COMPARISON_DISPATCH_AREA : StatisticsQueryKeys::DISPATCH_AREA;

        return match ($side->scopeGroup) {
            'public' => [$scopeKey => StatisticsFilterScope::Public->value],
            'state' => [
                $scopeKey => StatisticsFilterScope::State->value.':'.($side->scopeDetail ?? ''),
                $stateKey => $side->scopeDetail,
            ],
            'dispatch_area' => [
                $scopeKey => StatisticsFilterScope::DispatchArea->value.':'.($side->scopeDetail ?? ''),
                $dispatchKey => $side->scopeDetail,
            ],
            'hospital_cohort' => [
                $scopeKey => StatisticsFilterScope::HospitalCohort->value.':'.($side->scopeDetail ?? ''),
                $cohortKey => $side->scopeDetail,
            ],
            'my_hospitals' => '' !== ($side->scopeDetail ?? '')
                ? [
                    $scopeKey => StatisticsFilterScope::Hospital->value,
                    $hospitalKey => $side->scopeDetail,
                ]
                : [$scopeKey => StatisticsFilterScope::MyHospitals->value],
            default => [$scopeKey => StatisticsFilterScope::Public->value],
        };
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    private function periodParams(BenchmarkSelectionSideFormData $side, bool $isComparison): array
    {
        $periodKey = $isComparison ? StatisticsQueryKeys::COMPARISON_PERIOD : StatisticsQueryKeys::PERIOD;
        $yearKey = $isComparison ? StatisticsQueryKeys::COMPARISON_YEAR : StatisticsQueryKeys::YEAR;
        $monthKey = $isComparison ? StatisticsQueryKeys::COMPARISON_MONTH : StatisticsQueryKeys::MONTH;
        $quarterKey = $isComparison ? StatisticsQueryKeys::COMPARISON_QUARTER : StatisticsQueryKeys::QUARTER;

        $period = StatisticsFilterPeriod::tryFrom($side->period) ?? StatisticsFilterPeriod::AllTime;
        $params = [$periodKey => $period->value];

        return match ($period) {
            StatisticsFilterPeriod::AllTime, StatisticsFilterPeriod::All => $params,
            StatisticsFilterPeriod::Year => array_merge($params, [$yearKey => $side->periodYear]),
            StatisticsFilterPeriod::Quarter => array_merge($params, [
                $yearKey => $side->periodYear,
                $quarterKey => $side->periodQuarter,
            ]),
            StatisticsFilterPeriod::Month => array_merge($params, [
                $yearKey => $side->periodYear,
                $monthKey => $side->periodMonth,
            ]),
        };
    }
}
