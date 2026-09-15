<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\UI\Http\Controller;

use App\Statistics\CaseFlow\Application\CaseFlowPrivacyPolicy;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDashboardResult;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDistributionSlice;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowFlowMatrixRow;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowOriginSlice;
use App\Statistics\GeographicMap\Application\GeographicMapPayloadBuilder;

/**
 * @phpstan-type ChartPayload array<string, mixed>
 */
final readonly class CaseFlowChartPayloadFactory
{
    public function __construct(
        private GeographicMapPayloadBuilder $geographicMapPayloadBuilder,
    ) {
    }

    /**
     * @return ChartPayload
     */
    public function create(CaseFlowDashboardResult $result): array
    {
        $geographicMap = $this->geographicMapPayloadBuilder->build(
            $result->mode,
            $result->mapFeatures,
            $result->destinationHospitals,
            $result->isochrone,
            $result->singleHospital,
            $result->dispatchAreaFlow instanceof \App\Statistics\CaseFlow\Application\DTO\CaseFlowDispatchAreaFlow,
            $result->selectedDispatchAreaId,
            $result->omittedInsideDestinationHospitals,
            $result->omittedOutsideDestinationHospitals,
        );

        return [
            'mode' => $result->mode->value,
            'flowStackedBar' => $this->flowStackedBarPayload($result),
            'originBar' => $this->originBarPayload($result->originSlices, $result->kpis->totalCases),
            'transportTime' => $this->distributionPayload($result->transportTimeDistribution),
            'mapFeatures' => $geographicMap['mapFeatures'],
            'geographicMap' => $geographicMap,
        ];
    }

    /**
     * @return array{categories: list<string>, series: list<array{name: string, data: list<int>}>}
     */
    private function flowStackedBarPayload(CaseFlowDashboardResult $result): array
    {
        if (CaseFlowMode::SystemFlow !== $result->mode || [] === $result->flowMatrix) {
            return ['categories' => [], 'series' => []];
        }

        $tierKeys = ['1', '2', '3', CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY];
        $tierLabels = [
            '1' => 'Basic',
            '2' => 'Extended',
            '3' => 'Full',
            CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY => 'Suppressed',
        ];

        $categories = array_map(
            static fn (CaseFlowFlowMatrixRow $row): string => $row->originName,
            $result->flowMatrix,
        );

        $series = [];
        foreach ($tierKeys as $tierKey) {
            $data = [];
            foreach ($result->flowMatrix as $row) {
                $data[] = $row->destinationCounts[$tierKey] ?? 0;
            }
            $series[] = [
                'name' => $tierLabels[$tierKey],
                'data' => $data,
            ];
        }

        return ['categories' => $categories, 'series' => $series];
    }

    /**
     * @param list<CaseFlowOriginSlice> $slices
     *
     * @return array{labels: list<string>, values: list<int>, percents: list<float>}
     */
    private function originBarPayload(array $slices, int $totalCases): array
    {
        $labels = [];
        $values = [];
        $percents = [];
        $denominator = array_sum(array_map(
            static fn (CaseFlowOriginSlice $slice): int => $slice->caseCount,
            $slices,
        ));
        if ($denominator <= 0) {
            $denominator = $totalCases;
        }

        foreach ($slices as $slice) {
            $label = CaseFlowPrivacyPolicy::OTHER_ORIGIN_KEY === $slice->originName
                ? 'Other'
                : $slice->originName;
            $labels[] = $label;
            $values[] = $slice->caseCount;
            $percents[] = $denominator > 0 ? round(((float) $slice->caseCount / (float) $denominator) * 100.0, 1) : 0.0;
        }

        return ['labels' => $labels, 'values' => $values, 'percents' => $percents];
    }

    /**
     * @param list<CaseFlowDistributionSlice> $slices
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    private function distributionPayload(array $slices): array
    {
        return [
            'labels' => array_map(static fn (CaseFlowDistributionSlice $s): string => $s->key, $slices),
            'values' => array_map(static fn (CaseFlowDistributionSlice $s): int => $s->count, $slices),
        ];
    }
}
