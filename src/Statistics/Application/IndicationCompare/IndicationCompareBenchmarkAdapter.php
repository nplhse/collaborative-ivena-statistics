<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkDistributionRow;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareAggregationResult;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareDistributionRow;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareSideCounts;

final readonly class IndicationCompareBenchmarkAdapter
{
    public function toBenchmarkAggregation(IndicationCompareAggregationResult $result): BenchmarkAggregationResult
    {
        $distributionRows = array_map(
            static fn (IndicationCompareDistributionRow $row): BenchmarkDistributionRow => new BenchmarkDistributionRow(
                $row->dimension,
                $row->bucketKey,
                $row->bucketLabel,
                $row->sideACount,
                $row->sideBCount,
            ),
            $result->distributionRows,
        );

        return new BenchmarkAggregationResult(
            $this->toBenchmarkSideCounts($result->sideA),
            $this->toBenchmarkSideCounts($result->sideB),
            $distributionRows,
        );
    }

    public function buildUrgencyDistribution(
        IndicationCompareSideCounts $sideA,
        IndicationCompareSideCounts $sideB,
    ): BenchmarkDistribution {
        return $this->buildCountShareDistribution(
            BenchmarkMetricKey::Urgency,
            $sideA,
            $sideB,
            [
                ['1', 'stats.benchmark.urgency.emergency', $sideA->urgencyEmergency, $sideB->urgencyEmergency],
                ['2', 'stats.benchmark.urgency.inpatient', $sideA->urgencyInpatient, $sideB->urgencyInpatient],
                ['3', 'stats.benchmark.urgency.outpatient', $sideA->urgencyOutpatient, $sideB->urgencyOutpatient],
            ],
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $items
     */
    private function buildCountShareDistribution(
        BenchmarkMetricKey $dimension,
        IndicationCompareSideCounts $sideA,
        IndicationCompareSideCounts $sideB,
        array $items,
    ): BenchmarkDistribution {
        $buckets = [];
        foreach ($items as [$key, $labelKey, $primaryNumerator, $comparisonNumerator]) {
            $primaryShare = $this->share($primaryNumerator, $sideA->total);
            $comparisonShare = $this->share($comparisonNumerator, $sideB->total);
            $buckets[] = new BenchmarkDistributionBucket(
                $key,
                $labelKey,
                $primaryNumerator,
                $comparisonNumerator,
                $primaryShare,
                $comparisonShare,
                $comparisonShare > 0.0 ? $primaryShare / $comparisonShare : 0.0,
            );
        }

        return new BenchmarkDistribution($dimension, $buckets);
    }

    private function share(int $numerator, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return ((float) $numerator / (float) $total) * 100.0;
    }

    private function toBenchmarkSideCounts(IndicationCompareSideCounts $side): BenchmarkSideCounts
    {
        return new BenchmarkSideCounts(
            $side->total,
            $side->withPhysician,
            $side->resus,
            $side->cathlab,
            $side->cpr,
            $side->ventilated,
            $side->shock,
            $side->pregnant,
            $side->workAccident,
            $side->infectious,
            $side->urgencyEmergency,
            $side->urgencyInpatient,
            $side->urgencyOutpatient,
            $side->nightDaytime,
            $side->weekend,
            $side->age80Plus,
            $side->male,
            $side->female,
            $side->genderOther,
            $side->medianAge,
            $side->medianTransportMinutes,
            null,
        );
    }
}
