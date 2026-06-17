<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare;

use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareInsight;
use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareInsightSeverity;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareSideCounts;

final readonly class IndicationCompareInsightEngine
{
    private const int MIN_CASES_PER_SIDE = 30;

    private const int MAX_VISIBLE = 4;

    private const float MIN_ABSOLUTE_RATE_DELTA = 0.03;

    private const float NEUTRAL_RATIO_LOW = 0.85;

    private const float NEUTRAL_RATIO_HIGH = 1.15;

    /**
     * @return list<IndicationCompareInsight>
     */
    public function build(IndicationCompareSideCounts $sideA, IndicationCompareSideCounts $sideB): array
    {
        if ($sideA->total < self::MIN_CASES_PER_SIDE || $sideB->total < self::MIN_CASES_PER_SIDE) {
            return [];
        }

        $candidates = [];

        $this->addRateInsight($candidates, 'physician', 100, 1.5, $sideA->withPhysician, $sideA->total, $sideB->withPhysician, $sideB->total, true);
        $this->addRateInsight($candidates, 'resus', 95, 1.5, $sideA->resus, $sideA->total, $sideB->resus, $sideB->total, true);
        $this->addRateInsight($candidates, 'cathlab', 90, 1.5, $sideA->cathlab, $sideA->total, $sideB->cathlab, $sideB->total, true);
        $this->addRateInsight($candidates, 'urgency_emergency', 85, 1.3, $sideA->urgencyEmergency, $sideA->total, $sideB->urgencyEmergency, $sideB->total, true);
        $this->addRateInsight($candidates, 'age_80_plus', 70, 1.5, $sideA->age80Plus, $sideA->total, $sideB->age80Plus, $sideB->total, false);
        $this->addRateInsight($candidates, 'night_daytime', 60, 1.5, $sideA->nightDaytime, $sideA->total, $sideB->nightDaytime, $sideB->total, false);
        $this->addRateInsight($candidates, 'weekend', 55, 1.3, $sideA->weekend, $sideA->total, $sideB->weekend, $sideB->total, false);
        $this->addRateInsight($candidates, 'infectious', 45, 1.5, $sideA->infectious, $sideA->total, $sideB->infectious, $sideB->total, false);
        $this->addRateInsight($candidates, 'cpr', 75, 1.5, $sideA->cpr, $sideA->total, $sideB->cpr, $sideB->total, false);
        $this->addRateInsight($candidates, 'ventilation', 72, 1.5, $sideA->ventilated, $sideA->total, $sideB->ventilated, $sideB->total, false);
        $this->addRateInsight($candidates, 'shock', 70, 1.5, $sideA->shock, $sideA->total, $sideB->shock, $sideB->total, false);

        $this->addMedianAgeInsights($candidates, $sideA, $sideB);
        $this->addTransportTimeInsights($candidates, $sideA, $sideB);

        usort($candidates, static fn (IndicationCompareInsight $a, IndicationCompareInsight $b): int => $b->sortScore <=> $a->sortScore);

        $notable = array_values(array_filter(
            $candidates,
            static fn (IndicationCompareInsight $insight): bool => !str_ends_with($insight->id, '_neutral'),
        ));

        if ([] !== $notable) {
            return \array_slice($notable, 0, self::MAX_VISIBLE);
        }

        return \array_slice($candidates, 0, 1);
    }

    /**
     * @param list<IndicationCompareInsight> $candidates
     */
    private function addRateInsight(
        array &$candidates,
        string $id,
        int $priority,
        float $minRatio,
        int $numeratorA,
        int $denominatorA,
        int $numeratorB,
        int $denominatorB,
        bool $medicallyCritical,
    ): void {
        $rateA = $this->rate($numeratorA, $denominatorA);
        $rateB = $this->rate($numeratorB, $denominatorB);

        if ($rateB <= 0.0) {
            return;
        }

        $percentA = round(100.0 * $rateA, 1);
        $percentB = round(100.0 * $rateB, 1);
        $ratio = $rateA / $rateB;

        if ($ratio >= self::NEUTRAL_RATIO_LOW && $ratio <= self::NEUTRAL_RATIO_HIGH) {
            if ('physician' === $id) {
                $candidates[] = $this->buildInsight(
                    'physician_neutral',
                    IndicationCompareInsightSeverity::Neutral,
                    'stats.indication.compare.insight.physician_neutral',
                    round($ratio, 1),
                    $percentA,
                    $percentB,
                    (int) round((float) $priority * 0.5),
                );
            }

            return;
        }

        if (abs($rateA - $rateB) < self::MIN_ABSOLUTE_RATE_DELTA) {
            return;
        }

        if ($ratio < $minRatio) {
            return;
        }

        $candidates[] = $this->buildInsight(
            $id,
            $this->resolveHighSeverity($ratio, $medicallyCritical),
            'stats.indication.compare.insight.'.$id,
            round($ratio, 1),
            $percentA,
            $percentB,
            $this->sortScore($priority, $ratio, $denominatorA),
        );
    }

    /**
     * @param list<IndicationCompareInsight> $candidates
     */
    private function addMedianAgeInsights(array &$candidates, IndicationCompareSideCounts $sideA, IndicationCompareSideCounts $sideB): void
    {
        if (null === $sideA->medianAge || null === $sideB->medianAge) {
            return;
        }

        $diff = $sideA->medianAge - $sideB->medianAge;

        if ($diff <= -10.0) {
            $candidates[] = $this->buildInsight(
                'age_young',
                IndicationCompareInsightSeverity::Elevated,
                'stats.indication.compare.insight.age_young',
                round(abs($diff), 1),
                $sideA->medianAge,
                $sideB->medianAge,
                65,
            );
        }

        if ($diff >= 10.0) {
            $candidates[] = $this->buildInsight(
                'age_old',
                IndicationCompareInsightSeverity::Elevated,
                'stats.indication.compare.insight.age_old',
                round($diff / max($sideB->medianAge, 1.0), 1),
                $sideA->medianAge,
                $sideB->medianAge,
                65,
            );
        }
    }

    /**
     * @param list<IndicationCompareInsight> $candidates
     */
    private function addTransportTimeInsights(array &$candidates, IndicationCompareSideCounts $sideA, IndicationCompareSideCounts $sideB): void
    {
        if (null === $sideA->medianTransportMinutes || null === $sideB->medianTransportMinutes || $sideB->medianTransportMinutes <= 0.0) {
            return;
        }

        $ratio = $sideA->medianTransportMinutes / $sideB->medianTransportMinutes;

        if ($ratio >= 1.2) {
            $candidates[] = $this->buildInsight(
                'transport_time_long',
                IndicationCompareInsightSeverity::Elevated,
                'stats.indication.compare.insight.transport_time_long',
                round($ratio, 1),
                $sideA->medianTransportMinutes,
                $sideB->medianTransportMinutes,
                40,
            );
        } elseif ($ratio <= 0.8) {
            $candidates[] = $this->buildInsight(
                'transport_time_short',
                IndicationCompareInsightSeverity::Elevated,
                'stats.indication.compare.insight.transport_time_short',
                round($ratio, 1),
                $sideA->medianTransportMinutes,
                $sideB->medianTransportMinutes,
                35,
            );
        }
    }

    private function buildInsight(
        string $id,
        IndicationCompareInsightSeverity $severity,
        string $translationKey,
        float $ratio,
        float $percentA,
        float $percentB,
        int $sortScore,
    ): IndicationCompareInsight {
        return new IndicationCompareInsight(
            $id,
            $severity,
            $translationKey,
            $ratio,
            $percentA,
            $percentB,
            $sortScore,
        );
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    private function sortScore(int $priority, float $ratio, int $totalA): int
    {
        return (int) round((float) $priority * min($ratio, 5.0) * log((float) max($totalA, 2)));
    }

    private function resolveHighSeverity(float $ratio, bool $medicallyCritical): IndicationCompareInsightSeverity
    {
        if ($ratio >= 2.5 || ($medicallyCritical && $ratio >= 2.0)) {
            return IndicationCompareInsightSeverity::Critical;
        }

        if ($ratio >= 1.5) {
            return IndicationCompareInsightSeverity::Elevated;
        }

        return IndicationCompareInsightSeverity::Neutral;
    }
}
