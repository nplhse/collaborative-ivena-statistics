<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class HospitalInsightSelector
{
    private const float BASELINE_RATIO_HIGH = 1.1;

    private const float BASELINE_RATIO_LOW = 0.9;

    private const float VOLUME_CHANGE_THRESHOLD = 5.0;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<BenchmarkMetric> $metrics
     *
     * @return list<HospitalInsight>
     */
    public function select(
        ?float $allocationMomPercent,
        ?float $allocationYoyPercent,
        array $metrics,
        BenchmarkDistribution $indicationMix,
        ?float $rejectionRateDeltaPp,
        string $benchmarkingUrl,
        string $baselinePeriodLabel,
        string $reportingPeriodLabel,
        ?string $locale = null,
    ): array {
        $candidates = [];

        if (null !== $allocationYoyPercent && abs($allocationYoyPercent) >= self::VOLUME_CHANGE_THRESHOLD) {
            $candidates[] = new HospitalInsight(
                $this->trans('monthly_reminder.insight.volume_yoy.title', [], $locale),
                $this->trans('monthly_reminder.insight.volume_yoy.body', [
                    'percent' => $this->formatSignedPercent($allocationYoyPercent),
                ], $locale),
                $allocationYoyPercent >= 0 ? HospitalInsightTrend::Up : HospitalInsightTrend::Down,
            );
        } elseif (null !== $allocationMomPercent && abs($allocationMomPercent) >= self::VOLUME_CHANGE_THRESHOLD) {
            $candidates[] = new HospitalInsight(
                $this->trans('monthly_reminder.insight.volume_mom.title', [], $locale),
                $this->trans('monthly_reminder.insight.volume_mom.body', [
                    'percent' => $this->formatSignedPercent($allocationMomPercent),
                ], $locale),
                $allocationMomPercent >= 0 ? HospitalInsightTrend::Up : HospitalInsightTrend::Down,
            );
        }

        foreach ($metrics as $metric) {
            $insight = $this->baselineMetricInsight(
                $metric,
                $benchmarkingUrl,
                $baselinePeriodLabel,
                $reportingPeriodLabel,
                $locale,
            );
            if ($insight instanceof HospitalInsight) {
                $candidates[] = $insight;
            }
        }

        $indicationInsight = $this->indicationInsight(
            $indicationMix,
            $benchmarkingUrl,
            $baselinePeriodLabel,
            $reportingPeriodLabel,
            $locale,
        );
        if ($indicationInsight instanceof HospitalInsight) {
            $candidates[] = $indicationInsight;
        }

        if (null !== $rejectionRateDeltaPp && $rejectionRateDeltaPp <= -1.0) {
            $candidates[] = new HospitalInsight(
                $this->trans('monthly_reminder.insight.quality.title', [], $locale),
                $this->trans('monthly_reminder.insight.quality.body', [
                    'delta' => number_format(abs($rejectionRateDeltaPp), 1, '.', ''),
                ], $locale),
                HospitalInsightTrend::Up,
            );
        }

        return \array_slice($candidates, 0, 3);
    }

    private function baselineMetricInsight(
        BenchmarkMetric $metric,
        string $benchmarkingUrl,
        string $baselinePeriodLabel,
        string $reportingPeriodLabel,
        ?string $locale,
    ): ?HospitalInsight {
        if (BenchmarkMetricFormat::Percent !== $metric->format) {
            return null;
        }

        if ($metric->ratio < self::BASELINE_RATIO_HIGH && $metric->ratio > self::BASELINE_RATIO_LOW) {
            return null;
        }

        $key = match ($metric->key) {
            BenchmarkMetricKey::Resus => 'monthly_reminder.insight.resus',
            BenchmarkMetricKey::Cpr => 'monthly_reminder.insight.cpr',
            BenchmarkMetricKey::WithPhysician => 'monthly_reminder.insight.physician',
            default => 'monthly_reminder.insight.benchmark',
        };

        return new HospitalInsight(
            $this->trans($key.'.title', [], $locale),
            $this->trans($key.'.body', [
                'period' => $reportingPeriodLabel,
                'primary_percent' => number_format($metric->primaryValue, 1, '.', ''),
                'baseline_percent' => number_format($metric->comparisonValue, 1, '.', ''),
                'delta_pp' => $this->formatSignedPercent($metric->absoluteDelta),
                'baseline' => $baselinePeriodLabel,
            ], $locale),
            $metric->absoluteDelta >= 0 ? HospitalInsightTrend::Up : HospitalInsightTrend::Down,
            $benchmarkingUrl,
        );
    }

    private function indicationInsight(
        BenchmarkDistribution $mix,
        string $benchmarkingUrl,
        string $baselinePeriodLabel,
        string $reportingPeriodLabel,
        ?string $locale,
    ): ?HospitalInsight {
        $top = null;
        $maxDeviation = 0.0;
        foreach ($mix->buckets as $bucket) {
            $deviation = abs($bucket->primaryShare - $bucket->comparisonShare);
            if ($deviation > $maxDeviation) {
                $maxDeviation = $deviation;
                $top = $bucket;
            }
        }

        if (!$top instanceof BenchmarkDistributionBucket || $maxDeviation < 1.0) {
            return null;
        }

        return new HospitalInsight(
            $this->trans('monthly_reminder.insight.indication.title', [], $locale),
            $this->trans('monthly_reminder.insight.indication.body', [
                'period' => $reportingPeriodLabel,
                'indication' => $top->label,
                'primary_percent' => number_format($top->primaryShare, 1, '.', ''),
                'baseline_percent' => number_format($top->comparisonShare, 1, '.', ''),
                'delta_pp' => $this->formatSignedPercent($top->primaryShare - $top->comparisonShare),
                'baseline' => $baselinePeriodLabel,
            ], $locale),
            $top->primaryShare >= $top->comparisonShare
                ? HospitalInsightTrend::Up
                : HospitalInsightTrend::Down,
            $benchmarkingUrl,
        );
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function trans(string $id, array $parameters = [], ?string $locale = null): string
    {
        if (null !== $locale) {
            return $this->translator->trans($id, $parameters, null, $locale);
        }

        return $this->translator->trans($id, $parameters);
    }

    private function formatSignedPercent(float $value): string
    {
        $prefix = $value >= 0 ? '+' : '';

        return $prefix.number_format($value, 1, '.', '').'%';
    }
}
