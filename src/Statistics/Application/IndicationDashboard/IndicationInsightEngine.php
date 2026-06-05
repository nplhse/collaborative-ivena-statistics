<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

use App\Statistics\Application\IndicationDashboard\DTO\IndicationInsight;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationInsightSeverity;
use App\Statistics\Infrastructure\Query\IndicationDashboard\Dto\IndicationDashboardMetricsRow;

final readonly class IndicationInsightEngine
{
    private const int MIN_INDICATION_CASES = 30;

    private const int MIN_BASELINE_CASES = 100;

    private const int MAX_VISIBLE = 6;

    /**
     * @return list<IndicationInsight>
     */
    public function build(IndicationDashboardMetricsRow $metrics): array
    {
        if ($metrics->totalIndication < self::MIN_INDICATION_CASES || $metrics->totalBaseline < self::MIN_BASELINE_CASES) {
            return [];
        }

        $candidates = [];

        $this->addRateInsight($candidates, 'physician', 100, 1.5, $metrics->withPhysicianIndication, $metrics->totalIndication, $metrics->withPhysicianBaseline, $metrics->totalBaseline, true);
        $this->addRateInsight($candidates, 'resus', 95, 1.5, $metrics->resusIndication, $metrics->totalIndication, $metrics->resusBaseline, $metrics->totalBaseline, true);
        $this->addRateInsight($candidates, 'cathlab', 90, 1.5, $metrics->cathlabIndication, $metrics->totalIndication, $metrics->cathlabBaseline, $metrics->totalBaseline, true);
        $this->addRateInsight($candidates, 'urgency_emergency', 85, 1.3, $metrics->urgencyEmergencyIndication, $metrics->totalIndication, $metrics->urgencyEmergencyBaseline, $metrics->totalBaseline, true);
        $this->addRateInsight($candidates, 'age_80_plus', 70, 1.5, $metrics->age80PlusIndication, $metrics->totalIndication, $metrics->age80PlusBaseline, $metrics->totalBaseline, false);
        $this->addRateInsight($candidates, 'night_daytime', 60, 1.5, $metrics->nightDaytimeIndication, $metrics->totalIndication, $metrics->nightDaytimeBaseline, $metrics->totalBaseline, false);
        $this->addRateInsight($candidates, 'weekend', 55, 1.3, $metrics->weekendIndication, $metrics->totalIndication, $metrics->weekendBaseline, $metrics->totalBaseline, false);
        $this->addRateInsight($candidates, 'infectious', 45, 1.5, $metrics->infectiousIndication, $metrics->totalIndication, $metrics->infectiousBaseline, $metrics->totalBaseline, false);
        $this->addRateInsight($candidates, 'cpr', 75, 1.5, $metrics->cprIndication, $metrics->totalIndication, $metrics->cprBaseline, $metrics->totalBaseline, false);
        $this->addRateInsight($candidates, 'ventilation', 72, 1.5, $metrics->ventilatedIndication, $metrics->totalIndication, $metrics->ventilatedBaseline, $metrics->totalBaseline, false);
        $this->addRateInsight($candidates, 'shock', 70, 1.5, $metrics->shockIndication, $metrics->totalIndication, $metrics->shockBaseline, $metrics->totalBaseline, false);

        $this->addGenderDominanceInsight($candidates, $metrics);
        $this->addMedianAgeInsights($candidates, $metrics);
        $this->addTransportTimeInsights($candidates, $metrics);

        usort($candidates, static fn (IndicationInsight $a, IndicationInsight $b): int => $b->sortScore <=> $a->sortScore);

        return \array_slice($candidates, 0, self::MAX_VISIBLE);
    }

    /**
     * @param list<IndicationInsight> $candidates
     */
    private function addRateInsight(
        array &$candidates,
        string $id,
        int $priority,
        float $minRatio,
        int $indicationNumerator,
        int $indicationTotal,
        int $baselineNumerator,
        int $baselineTotal,
        bool $medicallyCritical,
    ): void {
        $indicationRate = $indicationTotal > 0
            ? (float) $indicationNumerator / (float) $indicationTotal
            : 0.0;
        $baselineRate = $baselineTotal > 0
            ? (float) $baselineNumerator / (float) $baselineTotal
            : 0.0;

        if ($baselineRate <= 0.0) {
            return;
        }

        $ratio = $indicationRate / $baselineRate;

        if ($ratio >= 0.85 && $ratio <= 1.15) {
            if ('physician' === $id || 'gender_balance' === $id) {
                $candidates[] = new IndicationInsight(
                    $id.'_neutral',
                    IndicationInsightSeverity::Neutral,
                    'stats.indication.insight.'.$id.'_neutral',
                    $ratio,
                    round(100.0 * $indicationRate, 1),
                    round(100.0 * $baselineRate, 1),
                    (int) round((float) $priority * 0.5),
                );
            }

            return;
        }

        if ($ratio < $minRatio) {
            return;
        }

        $severity = $this->resolveSeverity($ratio, $medicallyCritical);
        $sortScore = (int) round(
            (float) $priority * min($ratio, 5.0) * log((float) max($indicationTotal, 2)),
        );

        $candidates[] = new IndicationInsight(
            $id,
            $severity,
            'stats.indication.insight.'.$id,
            round($ratio, 1),
            round(100.0 * $indicationRate, 1),
            round(100.0 * $baselineRate, 1),
            $sortScore,
        );
    }

    /**
     * @param list<IndicationInsight> $candidates
     */
    private function addGenderDominanceInsight(array &$candidates, IndicationDashboardMetricsRow $metrics): void
    {
        $knownIndication = $metrics->maleIndication + $metrics->femaleIndication;
        $knownBaseline = $metrics->maleBaseline + $metrics->femaleBaseline;

        if ($knownIndication < 10 || $knownBaseline < 50) {
            return;
        }

        $maleShareIndication = (float) $metrics->maleIndication / (float) $knownIndication;
        $maleShareBaseline = (float) $metrics->maleBaseline / (float) $knownBaseline;

        if ($maleShareBaseline <= 0.0) {
            return;
        }

        if ($maleShareIndication >= 0.65) {
            $ratio = $maleShareIndication / $maleShareBaseline;
            if ($ratio >= 1.3) {
                $candidates[] = new IndicationInsight(
                    'gender_male_dominant',
                    $this->resolveSeverity($ratio, false),
                    'stats.indication.insight.gender_male_dominant',
                    round($ratio, 1),
                    round(100.0 * $maleShareIndication, 1),
                    round(100.0 * $maleShareBaseline, 1),
                    (int) round(50.0 * min($ratio, 5.0)),
                );
            }
        }

        $femaleShareIndication = (float) $metrics->femaleIndication / (float) $knownIndication;
        $femaleShareBaseline = (float) $metrics->femaleBaseline / (float) $knownBaseline;

        if ($femaleShareBaseline <= 0.0) {
            return;
        }

        if ($femaleShareIndication >= 0.65) {
            $ratio = $femaleShareIndication / $femaleShareBaseline;
            if ($ratio >= 1.3) {
                $candidates[] = new IndicationInsight(
                    'gender_female_dominant',
                    $this->resolveSeverity($ratio, false),
                    'stats.indication.insight.gender_female_dominant',
                    round($ratio, 1),
                    round(100.0 * $femaleShareIndication, 1),
                    round(100.0 * $femaleShareBaseline, 1),
                    (int) round(50.0 * min($ratio, 5.0)),
                );
            }
        }
    }

    /**
     * @param list<IndicationInsight> $candidates
     */
    private function addMedianAgeInsights(array &$candidates, IndicationDashboardMetricsRow $metrics): void
    {
        if (null === $metrics->medianAgeIndication || null === $metrics->medianAgeBaseline) {
            return;
        }

        $delta = $metrics->medianAgeIndication - $metrics->medianAgeBaseline;

        if ($delta <= -10.0) {
            $candidates[] = new IndicationInsight(
                'age_young',
                IndicationInsightSeverity::Elevated,
                'stats.indication.insight.age_young',
                round(abs($delta), 1),
                $metrics->medianAgeIndication,
                $metrics->medianAgeBaseline,
                65,
            );
        }

        if ($delta >= 10.0) {
            $candidates[] = new IndicationInsight(
                'age_old',
                IndicationInsightSeverity::Elevated,
                'stats.indication.insight.age_old',
                round($delta / max($metrics->medianAgeBaseline, 1.0), 1),
                $metrics->medianAgeIndication,
                $metrics->medianAgeBaseline,
                65,
            );
        }
    }

    /**
     * @param list<IndicationInsight> $candidates
     */
    private function addTransportTimeInsights(array &$candidates, IndicationDashboardMetricsRow $metrics): void
    {
        if (null === $metrics->medianTransportMinutesIndication || null === $metrics->medianTransportMinutesBaseline) {
            return;
        }

        if ($metrics->medianTransportMinutesBaseline <= 0.0) {
            return;
        }

        $ratio = $metrics->medianTransportMinutesIndication / $metrics->medianTransportMinutesBaseline;

        if ($ratio >= 1.2) {
            $candidates[] = new IndicationInsight(
                'transport_time_long',
                IndicationInsightSeverity::Elevated,
                'stats.indication.insight.transport_time_long',
                round($ratio, 1),
                $metrics->medianTransportMinutesIndication,
                $metrics->medianTransportMinutesBaseline,
                40,
            );
        } elseif ($ratio <= 0.8) {
            $candidates[] = new IndicationInsight(
                'transport_time_short',
                IndicationInsightSeverity::Elevated,
                'stats.indication.insight.transport_time_short',
                round($ratio, 1),
                $metrics->medianTransportMinutesIndication,
                $metrics->medianTransportMinutesBaseline,
                35,
            );
        }
    }

    private function resolveSeverity(float $ratio, bool $medicallyCritical): IndicationInsightSeverity
    {
        if ($ratio >= 2.5 || ($medicallyCritical && $ratio >= 2.0)) {
            return IndicationInsightSeverity::Critical;
        }

        if ($ratio >= 1.5) {
            return IndicationInsightSeverity::Elevated;
        }

        return IndicationInsightSeverity::Neutral;
    }
}
