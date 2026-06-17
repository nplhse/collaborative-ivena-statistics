<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Engagement\Application\Dto\MonthlyReminderInsight;
use App\Engagement\Application\Dto\MonthlyReminderInsightTrend;
use App\Engagement\Application\Dto\MonthlyReminderSegment;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyReminderInsightSelector
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
     * @return list<MonthlyReminderInsight>
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
    ): array {
        $candidates = [];

        if (null !== $allocationYoyPercent && abs($allocationYoyPercent) >= self::VOLUME_CHANGE_THRESHOLD) {
            $candidates[] = new MonthlyReminderInsight(
                $this->translator->trans('monthly_reminder.insight.volume_yoy.title'),
                $this->translator->trans('monthly_reminder.insight.volume_yoy.body', [
                    'percent' => $this->formatSignedPercent($allocationYoyPercent),
                ]),
                $allocationYoyPercent >= 0 ? MonthlyReminderInsightTrend::Up : MonthlyReminderInsightTrend::Down,
            );
        } elseif (null !== $allocationMomPercent && abs($allocationMomPercent) >= self::VOLUME_CHANGE_THRESHOLD) {
            $candidates[] = new MonthlyReminderInsight(
                $this->translator->trans('monthly_reminder.insight.volume_mom.title'),
                $this->translator->trans('monthly_reminder.insight.volume_mom.body', [
                    'percent' => $this->formatSignedPercent($allocationMomPercent),
                ]),
                $allocationMomPercent >= 0 ? MonthlyReminderInsightTrend::Up : MonthlyReminderInsightTrend::Down,
            );
        }

        foreach ($metrics as $metric) {
            $insight = $this->baselineMetricInsight(
                $metric,
                $benchmarkingUrl,
                $baselinePeriodLabel,
                $reportingPeriodLabel,
            );
            if ($insight instanceof MonthlyReminderInsight) {
                $candidates[] = $insight;
            }
        }

        $indicationInsight = $this->indicationInsight(
            $indicationMix,
            $benchmarkingUrl,
            $baselinePeriodLabel,
            $reportingPeriodLabel,
        );
        if ($indicationInsight instanceof MonthlyReminderInsight) {
            $candidates[] = $indicationInsight;
        }

        if (null !== $rejectionRateDeltaPp && $rejectionRateDeltaPp <= -1.0) {
            $candidates[] = new MonthlyReminderInsight(
                $this->translator->trans('monthly_reminder.insight.quality.title'),
                $this->translator->trans('monthly_reminder.insight.quality.body', [
                    'delta' => number_format(abs($rejectionRateDeltaPp), 1, '.', ''),
                ]),
                MonthlyReminderInsightTrend::Up,
            );
        }

        return \array_slice($candidates, 0, 3);
    }

    /**
     * @param array<string, int> $genderCounts
     *
     * @return list<MonthlyReminderSegment>
     */
    public function genderSegments(array $genderCounts, int $total): array
    {
        $colors = [
            AllocationGender::MALE->value => '#206bc4',
            AllocationGender::FEMALE->value => '#d63384',
            AllocationGender::OTHER->value => '#7950f2',
        ];

        $segments = [];
        foreach (AllocationGender::cases() as $case) {
            $count = $genderCounts[$case->value] ?? 0;
            $segments[] = new MonthlyReminderSegment(
                $this->translator->trans($case->label()),
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
                $colors[$case->value] ?? '#667382',
            );
        }

        return $segments;
    }

    /**
     * @param array<int, int> $urgencyCounts
     *
     * @return list<MonthlyReminderSegment>
     */
    public function urgencySegments(array $urgencyCounts, int $total): array
    {
        $colors = [
            AllocationUrgency::EMERGENCY->value => '#d63939',
            AllocationUrgency::INPATIENT->value => '#f59f00',
            AllocationUrgency::OUTPATIENT->value => '#2fb344',
        ];

        $segments = [];
        foreach (AllocationUrgency::cases() as $case) {
            $count = $urgencyCounts[$case->value] ?? 0;
            $segments[] = new MonthlyReminderSegment(
                $this->translator->trans($case->label()),
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
                $colors[$case->value] ?? '#667382',
            );
        }

        return $segments;
    }

    private function baselineMetricInsight(
        BenchmarkMetric $metric,
        string $benchmarkingUrl,
        string $baselinePeriodLabel,
        string $reportingPeriodLabel,
    ): ?MonthlyReminderInsight {
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

        return new MonthlyReminderInsight(
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.body', [
                'period' => $reportingPeriodLabel,
                'primary_percent' => number_format($metric->primaryValue, 1, '.', ''),
                'baseline_percent' => number_format($metric->comparisonValue, 1, '.', ''),
                'delta_pp' => $this->formatSignedPercent($metric->absoluteDelta),
                'baseline' => $baselinePeriodLabel,
            ]),
            $metric->absoluteDelta >= 0 ? MonthlyReminderInsightTrend::Up : MonthlyReminderInsightTrend::Down,
            $benchmarkingUrl,
        );
    }

    private function indicationInsight(
        BenchmarkDistribution $mix,
        string $benchmarkingUrl,
        string $baselinePeriodLabel,
        string $reportingPeriodLabel,
    ): ?MonthlyReminderInsight {
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

        return new MonthlyReminderInsight(
            $this->translator->trans('monthly_reminder.insight.indication.title'),
            $this->translator->trans('monthly_reminder.insight.indication.body', [
                'period' => $reportingPeriodLabel,
                'indication' => $top->label,
                'primary_percent' => number_format($top->primaryShare, 1, '.', ''),
                'baseline_percent' => number_format($top->comparisonShare, 1, '.', ''),
                'delta_pp' => $this->formatSignedPercent($top->primaryShare - $top->comparisonShare),
                'baseline' => $baselinePeriodLabel,
            ]),
            $top->primaryShare >= $top->comparisonShare
                ? MonthlyReminderInsightTrend::Up
                : MonthlyReminderInsightTrend::Down,
            $benchmarkingUrl,
        );
    }

    private function formatSignedPercent(float $value): string
    {
        $prefix = $value >= 0 ? '+' : '';

        return $prefix.number_format($value, 1, '.', '').'%';
    }
}
