<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkInsight;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkInsightDirection;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkInsightSeverity;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;

final readonly class BenchmarkInsightProvider
{
    private const int MIN_PRIMARY_CASES = 100;

    private const int MIN_COMPARISON_CASES = 500;

    private const int MAX_VISIBLE = 4;

    private const float MIN_ABSOLUTE_RATE_DELTA = 0.03;

    /**
     * @param list<BenchmarkMetric> $kpiMetrics
     *
     * @return list<BenchmarkInsight>
     */
    public function build(BenchmarkAggregationResult $aggregation, array $kpiMetrics): array
    {
        if ($aggregation->primary->total < self::MIN_PRIMARY_CASES
            || $aggregation->comparison->total < self::MIN_COMPARISON_CASES) {
            return [];
        }

        $candidates = [];
        $this->addRateInsights($candidates, $aggregation->primary, $aggregation->comparison);
        $this->addMetricInsights($candidates, $kpiMetrics);

        usort($candidates, static fn (BenchmarkInsight $a, BenchmarkInsight $b): int => $b->sortScore <=> $a->sortScore);

        $notable = array_values(array_filter(
            $candidates,
            $this->isNotable(...),
        ));

        return \array_slice($notable, 0, self::MAX_VISIBLE);
    }

    /**
     * @param list<BenchmarkInsight> $candidates
     */
    private function addRateInsights(array &$candidates, BenchmarkSideCounts $primary, BenchmarkSideCounts $comparison): void
    {
        $this->addRateInsight($candidates, 'physician', 100, 1.5, true, $primary->withPhysician, $primary->total, $comparison->withPhysician, $comparison->total);
        $this->addRateInsight($candidates, 'resus', 95, 1.5, true, $primary->resus, $primary->total, $comparison->resus, $comparison->total);
        $this->addRateInsight($candidates, 'cathlab', 90, 1.5, true, $primary->cathlab, $primary->total, $comparison->cathlab, $comparison->total);
        $this->addRateInsight($candidates, 'urgency_emergency', 85, 1.3, true, $primary->urgencyEmergency, $primary->total, $comparison->urgencyEmergency, $comparison->total);
        $this->addRateInsight($candidates, 'age_80_plus', 70, 1.5, false, $primary->age80Plus, $primary->total, $comparison->age80Plus, $comparison->total);
        $this->addRateInsight($candidates, 'night_daytime', 60, 1.5, false, $primary->nightDaytime, $primary->total, $comparison->nightDaytime, $comparison->total);
        $this->addRateInsight($candidates, 'weekend', 55, 1.3, false, $primary->weekend, $primary->total, $comparison->weekend, $comparison->total);
        $this->addRateInsight($candidates, 'cpr', 75, 1.5, false, $primary->cpr, $primary->total, $comparison->cpr, $comparison->total);
        $this->addRateInsight($candidates, 'ventilation', 72, 1.5, false, $primary->ventilated, $primary->total, $comparison->ventilated, $comparison->total);
        $this->addRateInsight($candidates, 'shock', 70, 1.5, false, $primary->shock, $primary->total, $comparison->shock, $comparison->total);
        $this->addGenderInsight($candidates, $primary, $comparison);
    }

    /**
     * @param list<BenchmarkInsight> $candidates
     * @param list<BenchmarkMetric>  $kpiMetrics
     */
    private function addMetricInsights(array &$candidates, array $kpiMetrics): void
    {
        $medianAge = $this->findMetric($kpiMetrics, BenchmarkMetricKey::MedianAge);
        if ($medianAge instanceof BenchmarkMetric
            && $medianAge->primaryValue > 0.0
            && $medianAge->comparisonValue > 0.0) {
            $delta = $medianAge->primaryValue - $medianAge->comparisonValue;
            if ($delta >= 10.0) {
                $candidates[] = $this->buildInsight(
                    'age_old',
                    BenchmarkInsightDirection::Above,
                    BenchmarkInsightSeverity::Elevated,
                    $medianAge->ratio,
                    $medianAge->primaryValue,
                    $medianAge->comparisonValue,
                    65,
                );
            } elseif ($delta <= -10.0) {
                $candidates[] = $this->buildInsight(
                    'age_young',
                    BenchmarkInsightDirection::Below,
                    BenchmarkInsightSeverity::Elevated,
                    abs($medianAge->ratio > 0.0 ? $medianAge->ratio : 1.0),
                    $medianAge->primaryValue,
                    $medianAge->comparisonValue,
                    65,
                );
            }
        }

        $medianTransport = $this->findMetric($kpiMetrics, BenchmarkMetricKey::MedianTransport);
        if ($medianTransport instanceof BenchmarkMetric
            && $medianTransport->comparisonValue > 0.0) {
            if ($medianTransport->ratio >= 1.2) {
                $candidates[] = $this->buildInsight(
                    'transport_time_long',
                    BenchmarkInsightDirection::Above,
                    BenchmarkInsightSeverity::Elevated,
                    $medianTransport->ratio,
                    $medianTransport->primaryValue,
                    $medianTransport->comparisonValue,
                    50,
                );
            } elseif ($medianTransport->ratio <= 0.8) {
                $candidates[] = $this->buildInsight(
                    'transport_time_short',
                    BenchmarkInsightDirection::Below,
                    BenchmarkInsightSeverity::Elevated,
                    $medianTransport->ratio,
                    $medianTransport->primaryValue,
                    $medianTransport->comparisonValue,
                    45,
                );
            }
        }
    }

    /**
     * @param list<BenchmarkInsight> $candidates
     */
    private function addRateInsight(
        array &$candidates,
        string $id,
        int $priority,
        float $minRatio,
        bool $medicallyCritical,
        int $primaryNumerator,
        int $primaryTotal,
        int $comparisonNumerator,
        int $comparisonTotal,
    ): void {
        $primaryRate = $primaryTotal > 0 ? (float) $primaryNumerator / (float) $primaryTotal : 0.0;
        $comparisonRate = $comparisonTotal > 0 ? (float) $comparisonNumerator / (float) $comparisonTotal : 0.0;

        if ($comparisonRate <= 0.0) {
            return;
        }

        $ratio = $primaryRate / $comparisonRate;

        if ($ratio >= 0.90 && $ratio <= 1.10) {
            if ('physician' === $id) {
                $candidates[] = $this->buildInsight(
                    $id.'_neutral',
                    BenchmarkInsightDirection::Neutral,
                    BenchmarkInsightSeverity::Neutral,
                    round($ratio, 1),
                    round(100.0 * $primaryRate, 1),
                    round(100.0 * $comparisonRate, 1),
                    (int) round((float) $priority * 0.5),
                );
            }

            return;
        }

        if (abs($primaryRate - $comparisonRate) < self::MIN_ABSOLUTE_RATE_DELTA) {
            return;
        }

        if ($ratio >= 1.0) {
            if ($ratio < $minRatio) {
                return;
            }

            $candidates[] = $this->buildInsight(
                $id,
                BenchmarkInsightDirection::Above,
                $this->resolveSeverity($ratio, $medicallyCritical),
                round($ratio, 1),
                round(100.0 * $primaryRate, 1),
                round(100.0 * $comparisonRate, 1),
                $this->sortScore($priority, $ratio, $primaryTotal),
            );

            return;
        }

        $inverseRatio = $comparisonRate / max($primaryRate, 0.00001);
        if ($inverseRatio < $minRatio) {
            return;
        }

        $candidates[] = $this->buildInsight(
            $id.'_low',
            BenchmarkInsightDirection::Below,
            $this->resolveLowSeverity($ratio, $medicallyCritical),
            round($ratio, 1),
            round(100.0 * $primaryRate, 1),
            round(100.0 * $comparisonRate, 1),
            $this->sortScore($priority, $inverseRatio, $primaryTotal),
        );
    }

    /**
     * @param list<BenchmarkInsight> $candidates
     */
    private function addGenderInsight(array &$candidates, BenchmarkSideCounts $primary, BenchmarkSideCounts $comparison): void
    {
        $knownPrimary = $primary->male + $primary->female;
        $knownComparison = $comparison->male + $comparison->female;

        if ($knownPrimary < 10 || $knownComparison < 50) {
            return;
        }

        $maleSharePrimary = (float) $primary->male / (float) $knownPrimary;
        $maleShareComparison = (float) $comparison->male / (float) $knownComparison;

        if ($maleShareComparison <= 0.0) {
            return;
        }

        $ratio = $maleSharePrimary / $maleShareComparison;
        if ($ratio >= 0.90 && $ratio <= 1.10) {
            $candidates[] = $this->buildInsight(
                'gender_balance',
                BenchmarkInsightDirection::Neutral,
                BenchmarkInsightSeverity::Neutral,
                round($ratio, 1),
                round(100.0 * $maleSharePrimary, 1),
                round(100.0 * $maleShareComparison, 1),
                20,
            );
        }
    }

    /**
     * @param list<BenchmarkMetric> $kpiMetrics
     */
    private function findMetric(array $kpiMetrics, BenchmarkMetricKey $key): ?BenchmarkMetric
    {
        foreach ($kpiMetrics as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        return null;
    }

    private function buildInsight(
        string $id,
        BenchmarkInsightDirection $direction,
        BenchmarkInsightSeverity $severity,
        float $ratio,
        float $primaryDisplay,
        float $comparisonDisplay,
        int $sortScore,
    ): BenchmarkInsight {
        return new BenchmarkInsight(
            $id,
            $direction,
            $severity,
            'stats.benchmark.insight.'.$id,
            $ratio,
            $primaryDisplay,
            $comparisonDisplay,
            $sortScore,
        );
    }

    private function isNotable(BenchmarkInsight $insight): bool
    {
        if (BenchmarkInsightDirection::Neutral === $insight->direction) {
            return false;
        }

        if ('gender_balance' === $insight->id) {
            return false;
        }

        return !str_ends_with($insight->id, '_neutral');
    }

    private function sortScore(int $priority, float $ratio, int $primaryTotal): int
    {
        return (int) round((float) $priority * min($ratio, 5.0) * log((float) max($primaryTotal, 2)));
    }

    private function resolveSeverity(float $ratio, bool $medicallyCritical): BenchmarkInsightSeverity
    {
        if ($ratio >= 2.5 || ($medicallyCritical && $ratio >= 2.0)) {
            return BenchmarkInsightSeverity::Critical;
        }

        if ($ratio >= 1.5) {
            return BenchmarkInsightSeverity::Elevated;
        }

        return BenchmarkInsightSeverity::Neutral;
    }

    private function resolveLowSeverity(float $ratio, bool $medicallyCritical): BenchmarkInsightSeverity
    {
        if ($ratio <= 0.5 || ($medicallyCritical && $ratio <= 0.5)) {
            return BenchmarkInsightSeverity::Critical;
        }

        if ($ratio <= 0.8) {
            return BenchmarkInsightSeverity::Elevated;
        }

        return BenchmarkInsightSeverity::Neutral;
    }
}
