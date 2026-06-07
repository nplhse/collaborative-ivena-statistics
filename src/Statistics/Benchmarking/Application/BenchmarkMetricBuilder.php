<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application;

use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsShiftBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkDistributionRow;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;

final readonly class BenchmarkMetricBuilder
{
    private const int MIN_INDICATION_PRIMARY = 20;

    private const int MIN_INDICATION_COMPARISON = 50;

    private const int TOP_INDICATION_COUNT = 10;

    public const int INDICATION_MIX_INITIAL_COUNT = 5;

    public const float INDICATION_OVER_RATIO = 1.1;

    public const float INDICATION_UNDER_RATIO = 0.9;

    /**
     * @return list<BenchmarkMetric>
     */
    public function buildKpiMetrics(BenchmarkAggregationResult $result): array
    {
        $primary = $result->primary;
        $comparison = $result->comparison;

        return [
            $this->countMetric(BenchmarkMetricKey::Total, $primary->total, $comparison->total),
            $this->rateMetric(BenchmarkMetricKey::WithPhysician, $primary->withPhysician, $primary->total, $comparison->withPhysician, $comparison->total),
            $this->rateMetric(BenchmarkMetricKey::Resus, $primary->resus, $primary->total, $comparison->resus, $comparison->total),
            $this->rateMetric(BenchmarkMetricKey::Cathlab, $primary->cathlab, $primary->total, $comparison->cathlab, $comparison->total),
            $this->absoluteMetric(BenchmarkMetricKey::MedianAge, $primary->medianAge, $comparison->medianAge, BenchmarkMetricFormat::Years),
            $this->rateMetric(BenchmarkMetricKey::Age80Plus, $primary->age80Plus, $primary->total, $comparison->age80Plus, $comparison->total),
            $this->rateMetric(BenchmarkMetricKey::NightDaytime, $primary->nightDaytime, $primary->total, $comparison->nightDaytime, $comparison->total),
            $this->rateMetric(BenchmarkMetricKey::Weekend, $primary->weekend, $primary->total, $comparison->weekend, $comparison->total),
            $this->absoluteMetric(BenchmarkMetricKey::MedianTransport, $primary->medianTransportMinutes, $comparison->medianTransportMinutes, BenchmarkMetricFormat::Minutes),
        ];
    }

    public function buildIndicationMix(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $rows = $this->rowsForDimension($result->distributionRows, 'indication');
        $buckets = [];

        foreach ($rows as $row) {
            if ($row->primaryCount < self::MIN_INDICATION_PRIMARY || $row->comparisonCount < self::MIN_INDICATION_COMPARISON) {
                continue;
            }

            $primaryShare = $this->share($row->primaryCount, $result->primary->total);
            $comparisonShare = $this->share($row->comparisonCount, $result->comparison->total);
            if ($comparisonShare <= 0.0) {
                continue;
            }

            $buckets[] = new BenchmarkDistributionBucket(
                $row->bucketKey,
                $row->bucketLabel ?? $row->bucketKey,
                $row->primaryCount,
                $row->comparisonCount,
                $primaryShare,
                $comparisonShare,
                $primaryShare / $comparisonShare,
            );
        }

        usort($buckets, static fn (BenchmarkDistributionBucket $a, BenchmarkDistributionBucket $b): int => $b->ratio <=> $a->ratio);

        $over = \array_slice(array_values(array_filter($buckets, static fn (BenchmarkDistributionBucket $b): bool => $b->ratio >= self::INDICATION_OVER_RATIO)), 0, self::TOP_INDICATION_COUNT);
        $under = \array_slice(array_values(array_filter($buckets, static fn (BenchmarkDistributionBucket $b): bool => $b->ratio <= self::INDICATION_UNDER_RATIO)), 0, self::TOP_INDICATION_COUNT);
        usort($under, static fn (BenchmarkDistributionBucket $a, BenchmarkDistributionBucket $b): int => $a->ratio <=> $b->ratio);

        return new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, [...$over, ...$under]);
    }

    public function buildGenderDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $distribution = $this->buildShareDistribution(
            BenchmarkMetricKey::Gender,
            $result,
            'gender',
            [
                'male' => 'stats.benchmark.gender.male',
                'female' => 'stats.benchmark.gender.female',
                'other' => 'stats.benchmark.gender.other',
            ],
        );

        $buckets = array_values(array_filter(
            $distribution->buckets,
            static fn (BenchmarkDistributionBucket $bucket): bool => 'unknown' !== $bucket->key,
        ));

        return new BenchmarkDistribution(BenchmarkMetricKey::Gender, $buckets);
    }

    public function buildAgeGroupDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $labelKeys = [
            '0_17' => 'stats.benchmark.age_group.0_17',
            '18_29' => 'stats.benchmark.age_group.18_29',
            '30_39' => 'stats.benchmark.age_group.30_39',
            '40_49' => 'stats.benchmark.age_group.40_49',
            '50_59' => 'stats.benchmark.age_group.50_59',
            '60_69' => 'stats.benchmark.age_group.60_69',
            '70_79' => 'stats.benchmark.age_group.70_79',
            '80_89' => 'stats.benchmark.age_group.80_89',
            '90_99' => 'stats.benchmark.age_group.90_99',
        ];

        return $this->buildOrderedShareDistribution(
            BenchmarkMetricKey::AgeGroups,
            $result,
            'age_group',
            StatisticsAgeGroupBucketSql::DISPLAY_BUCKET_KEYS,
            $labelKeys,
        );
    }

    public function buildTransportTimeDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $labelKeys = [];
        foreach (StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS as $bucketKey) {
            $labelKeys[$bucketKey] = 'statistics.distribution.transport_time_bucket.'.$bucketKey;
        }

        return $this->buildOrderedShareDistribution(
            BenchmarkMetricKey::TransportTimes,
            $result,
            'transport_time',
            StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS,
            $labelKeys,
        );
    }

    public function buildTransportTypeDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $orderedKeys = [];
        $labelKeys = [];
        foreach (AllocationStatsTransportTypeProjectionCode::displayOrder() as $case) {
            $key = (string) $case->value;
            $orderedKeys[] = $key;
            $labelKeys[$key] = match ($case) {
                AllocationStatsTransportTypeProjectionCode::Ground => 'stats.indication.transport.ground',
                AllocationStatsTransportTypeProjectionCode::Air => 'stats.indication.transport.air',
            };
        }

        return $this->buildOrderedShareDistribution(
            BenchmarkMetricKey::TransportType,
            $result,
            'transport_type',
            $orderedKeys,
            $labelKeys,
        );
    }

    public function buildDayTimeBucketDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $labels = [];
        foreach (AllocationStatsDayTimeBucketProjectionCode::displayOrder() as $case) {
            $labels[(string) $case->value] = match ($case) {
                AllocationStatsDayTimeBucketProjectionCode::Morning => 'stats.benchmark.day_time.morning',
                AllocationStatsDayTimeBucketProjectionCode::Afternoon => 'stats.benchmark.day_time.afternoon',
                AllocationStatsDayTimeBucketProjectionCode::Evening => 'stats.benchmark.day_time.evening',
                AllocationStatsDayTimeBucketProjectionCode::Night => 'stats.benchmark.day_time.night',
            };
        }

        return $this->buildShareDistribution(
            BenchmarkMetricKey::DayTimeBuckets,
            $result,
            'day_time_bucket',
            $labels,
        );
    }

    public function buildShiftBucketDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $labels = [];
        foreach (AllocationStatsShiftBucketProjectionCode::displayOrder() as $case) {
            $labels[(string) $case->value] = match ($case) {
                AllocationStatsShiftBucketProjectionCode::EarlyShift => 'stats.benchmark.shift.early',
                AllocationStatsShiftBucketProjectionCode::LateShift => 'stats.benchmark.shift.late',
                AllocationStatsShiftBucketProjectionCode::NightShift => 'stats.benchmark.shift.night',
            };
        }

        return $this->buildShareDistribution(
            BenchmarkMetricKey::ShiftBuckets,
            $result,
            'shift_bucket',
            $labels,
        );
    }

    public function buildUrgencyDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        return $this->buildShareDistribution(
            BenchmarkMetricKey::Urgency,
            $result,
            'urgency',
            [
                '1' => 'stats.benchmark.urgency.emergency',
                '2' => 'stats.benchmark.urgency.inpatient',
                '3' => 'stats.benchmark.urgency.outpatient',
            ],
        );
    }

    public function buildResourcesDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $primary = $result->primary;
        $comparison = $result->comparison;

        return $this->buildCountShareDistribution(
            BenchmarkMetricKey::ResourceProfile,
            $primary,
            $comparison,
            [
                ['cathlab', 'stats.benchmark.resource.cathlab', $primary->cathlab, $comparison->cathlab],
                ['resus', 'stats.benchmark.resource.resus', $primary->resus, $comparison->resus],
            ],
        );
    }

    public function buildClinicalFeaturesDistribution(BenchmarkAggregationResult $result): BenchmarkDistribution
    {
        $primary = $result->primary;
        $comparison = $result->comparison;

        return $this->buildCountShareDistribution(
            BenchmarkMetricKey::ClinicalFeatures,
            $primary,
            $comparison,
            [
                ['with_physician', 'statistics.distribution.dim.is_with_physician', $primary->withPhysician, $comparison->withPhysician],
                ['cpr', 'statistics.distribution.dim.is_cpr', $primary->cpr, $comparison->cpr],
                ['ventilated', 'statistics.distribution.dim.is_ventilated', $primary->ventilated, $comparison->ventilated],
                ['shock', 'stats.analysis.feature.is_shock', $primary->shock, $comparison->shock],
                ['pregnant', 'stats.analysis.feature.is_pregnant', $primary->pregnant, $comparison->pregnant],
                ['work_accident', 'stats.analysis.feature.is_work_accident', $primary->workAccident, $comparison->workAccident],
                ['infectious', 'field.infection', $primary->infectious, $comparison->infectious],
            ],
        );
    }

    /**
     * @param list<BenchmarkMetric> $kpiMetrics
     */
    public function metricByKey(array $kpiMetrics, BenchmarkMetricKey $key): ?BenchmarkMetric
    {
        foreach ($kpiMetrics as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, string> $labelKeys
     */
    private function buildShareDistribution(
        BenchmarkMetricKey $dimension,
        BenchmarkAggregationResult $result,
        string $rowDimension,
        array $labelKeys,
    ): BenchmarkDistribution {
        $rows = $this->rowsForDimension($result->distributionRows, $rowDimension);
        $buckets = [];

        foreach ($rows as $row) {
            $primaryShare = $this->share($row->primaryCount, $result->primary->total);
            $comparisonShare = $this->share($row->comparisonCount, $result->comparison->total);
            $buckets[] = new BenchmarkDistributionBucket(
                $row->bucketKey,
                $labelKeys[$row->bucketKey] ?? $row->bucketKey,
                $row->primaryCount,
                $row->comparisonCount,
                $primaryShare,
                $comparisonShare,
                $comparisonShare > 0.0 ? $primaryShare / $comparisonShare : 0.0,
            );
        }

        return new BenchmarkDistribution($dimension, $buckets);
    }

    /**
     * @param list<string>              $orderedBucketKeys
     * @param array<int|string, string> $labelKeys
     */
    private function buildOrderedShareDistribution(
        BenchmarkMetricKey $dimension,
        BenchmarkAggregationResult $result,
        string $rowDimension,
        array $orderedBucketKeys,
        array $labelKeys,
    ): BenchmarkDistribution {
        $rowsByKey = [];
        foreach ($this->rowsForDimension($result->distributionRows, $rowDimension) as $row) {
            $rowsByKey[$row->bucketKey] = $row;
        }

        $buckets = [];
        foreach ($orderedBucketKeys as $bucketKey) {
            if (!isset($labelKeys[$bucketKey])) {
                continue;
            }

            $row = $rowsByKey[$bucketKey] ?? null;
            $primaryCount = null !== $row ? $row->primaryCount : 0;
            $comparisonCount = null !== $row ? $row->comparisonCount : 0;
            $primaryShare = $this->share($primaryCount, $result->primary->total);
            $comparisonShare = $this->share($comparisonCount, $result->comparison->total);
            $buckets[] = new BenchmarkDistributionBucket(
                $bucketKey,
                $labelKeys[$bucketKey],
                $primaryCount,
                $comparisonCount,
                $primaryShare,
                $comparisonShare,
                $comparisonShare > 0.0 ? $primaryShare / $comparisonShare : 0.0,
            );
        }

        return new BenchmarkDistribution($dimension, $buckets);
    }

    /**
     * @param list<BenchmarkDistributionRow> $rows
     *
     * @return list<BenchmarkDistributionRow>
     */
    private function rowsForDimension(array $rows, string $dimension): array
    {
        return array_values(array_filter(
            $rows,
            static fn (BenchmarkDistributionRow $row): bool => $row->dimension === $dimension,
        ));
    }

    private function countMetric(BenchmarkMetricKey $key, int $primary, int $comparison): BenchmarkMetric
    {
        return new BenchmarkMetric(
            $key,
            (float) $primary,
            (float) $comparison,
            (float) ($primary - $comparison),
            $comparison > 0 ? ((float) ($primary - $comparison) / (float) $comparison) * 100.0 : 0.0,
            $comparison > 0 ? (float) $primary / (float) $comparison : 0.0,
            BenchmarkMetricFormat::Count,
            $primary,
            max(1, $primary),
            $comparison,
            max(1, $comparison),
        );
    }

    private function rateMetric(
        BenchmarkMetricKey $key,
        int $primaryNumerator,
        int $primaryTotal,
        int $comparisonNumerator,
        int $comparisonTotal,
    ): BenchmarkMetric {
        $primaryRate = $this->share($primaryNumerator, $primaryTotal);
        $comparisonRate = $this->share($comparisonNumerator, $comparisonTotal);

        return new BenchmarkMetric(
            $key,
            $primaryRate,
            $comparisonRate,
            $primaryRate - $comparisonRate,
            $comparisonRate > 0.0 ? (($primaryRate - $comparisonRate) / $comparisonRate) * 100.0 : 0.0,
            $comparisonRate > 0.0 ? $primaryRate / $comparisonRate : 0.0,
            BenchmarkMetricFormat::Percent,
            $primaryNumerator,
            $primaryTotal,
            $comparisonNumerator,
            $comparisonTotal,
        );
    }

    private function absoluteMetric(
        BenchmarkMetricKey $key,
        ?float $primary,
        ?float $comparison,
        BenchmarkMetricFormat $format,
    ): BenchmarkMetric {
        $primaryValue = $primary ?? 0.0;
        $comparisonValue = $comparison ?? 0.0;

        return new BenchmarkMetric(
            $key,
            $primaryValue,
            $comparisonValue,
            $primaryValue - $comparisonValue,
            $comparisonValue > 0.0 ? (($primaryValue - $comparisonValue) / $comparisonValue) * 100.0 : 0.0,
            $comparisonValue > 0.0 ? $primaryValue / $comparisonValue : 0.0,
            $format,
        );
    }

    private function share(int $numerator, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return ((float) $numerator / (float) $total) * 100.0;
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $items
     */
    private function buildCountShareDistribution(
        BenchmarkMetricKey $dimension,
        BenchmarkSideCounts $primary,
        BenchmarkSideCounts $comparison,
        array $items,
    ): BenchmarkDistribution {
        $buckets = [];
        foreach ($items as [$key, $labelKey, $primaryNumerator, $comparisonNumerator]) {
            $primaryShare = $this->share($primaryNumerator, $primary->total);
            $comparisonShare = $this->share($comparisonNumerator, $comparison->total);
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
}
