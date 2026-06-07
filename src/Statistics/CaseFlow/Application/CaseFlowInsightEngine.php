<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use App\Statistics\CaseFlow\Application\DTO\CaseFlowInsight;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowInsightSeverity;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowRegionalMetricsRow;

final class CaseFlowInsightEngine
{
    private const int MAX_VISIBLE = 4;

    /**
     * @param list<CaseFlowOriginRow>                                                        $originRows
     * @param array{meanTransport: ?float, medianTransport: ?float, fullTierPercent: ?float} $baseline
     *
     * @return list<CaseFlowInsight>
     */
    public function build(
        CaseFlowMode $mode,
        CaseFlowRegionalMetricsRow $metrics,
        array $originRows,
        array $baseline,
    ): array {
        $minCases = CaseFlowMode::HospitalOrigin === $mode
            ? CaseFlowPrivacyPolicy::MIN_HOSPITAL_INSIGHT_CASES
            : CaseFlowPrivacyPolicy::MIN_SYSTEM_INSIGHT_CASES;

        if ($metrics->totalCases < $minCases) {
            return [];
        }

        $candidates = [];

        $this->addRegionalInsight($candidates, $metrics);
        $this->addCentralizationInsight($candidates, $metrics, $baseline);
        $this->addTransportInsight($candidates, $metrics, $baseline);
        $this->addUrgencyConcentrationInsight($candidates, $metrics, $originRows);

        usort($candidates, static fn (CaseFlowInsight $a, CaseFlowInsight $b): int => $b->sortScore <=> $a->sortScore);

        return \array_slice($candidates, 0, self::MAX_VISIBLE);
    }

    /**
     * @param list<CaseFlowInsight> $candidates
     */
    private function addRegionalInsight(array &$candidates, CaseFlowRegionalMetricsRow $metrics): void
    {
        if ($metrics->totalCases <= 0) {
            return;
        }

        $share = ((float) $metrics->regionalCases / (float) $metrics->totalCases) * 100.0;
        if ($share < 70.0) {
            return;
        }

        $candidates[] = new CaseFlowInsight(
            'predominantly_regional',
            CaseFlowInsightSeverity::Info,
            'stats.case_flow.insight.predominantly_regional',
            ['share' => round($share, 1)],
            (int) round($share),
            sprintf('%.0f%%', round($share, 0)),
        );
    }

    /**
     * @param list<CaseFlowInsight>                                                          $candidates
     * @param array{meanTransport: ?float, medianTransport: ?float, fullTierPercent: ?float} $baseline
     */
    private function addCentralizationInsight(array &$candidates, CaseFlowRegionalMetricsRow $metrics, array $baseline): void
    {
        if ($metrics->totalCases <= 0) {
            return;
        }

        $share = ((float) $metrics->fullTierCases / (float) $metrics->totalCases) * 100.0;
        $baselineShare = $baseline['fullTierPercent'] ?? 0.0;

        if ($share < 45.0 || ($baselineShare > 0 && $share < $baselineShare * 1.3)) {
            return;
        }

        $candidates[] = new CaseFlowInsight(
            'high_centralization',
            CaseFlowInsightSeverity::Elevated,
            'stats.case_flow.insight.high_centralization',
            ['share' => round($share, 1)],
            (int) round($share * 1.5),
            sprintf('%.0f%%', round($share, 0)),
        );
    }

    /**
     * @param list<CaseFlowInsight>                                                          $candidates
     * @param array{meanTransport: ?float, medianTransport: ?float, fullTierPercent: ?float} $baseline
     */
    private function addTransportInsight(array &$candidates, CaseFlowRegionalMetricsRow $metrics, array $baseline): void
    {
        $mean = $metrics->meanTransportMinutes;
        $baselineMean = $baseline['meanTransport'] ?? null;

        if (null === $mean || null === $baselineMean || $baselineMean <= 0) {
            return;
        }

        if ($mean < $baselineMean * 1.2) {
            return;
        }

        $ratio = $mean / $baselineMean;

        $candidates[] = new CaseFlowInsight(
            'elevated_transport_time',
            CaseFlowInsightSeverity::Elevated,
            'stats.case_flow.insight.elevated_transport_time',
            [
                'mean' => round($mean, 0),
                'baselineMean' => round($baselineMean, 0),
            ],
            (int) round($ratio * 50.0),
            sprintf('%.1f×', round($ratio, 1)),
        );
    }

    /**
     * @param list<CaseFlowInsight>   $candidates
     * @param list<CaseFlowOriginRow> $originRows
     */
    private function addUrgencyConcentrationInsight(array &$candidates, CaseFlowRegionalMetricsRow $metrics, array $originRows): void
    {
        if ($metrics->emergencyCases <= 0) {
            return;
        }

        foreach ($originRows as $row) {
            if ($row->emergencyCount < CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL) {
                continue;
            }

            $share = ((float) $row->emergencyCount / (float) $metrics->emergencyCases) * 100.0;
            if ($share < 40.0) {
                continue;
            }

            $candidates[] = new CaseFlowInsight(
                'urgency_concentration_origin',
                CaseFlowInsightSeverity::Elevated,
                'stats.case_flow.insight.urgency_concentration_origin',
                [
                    'share' => round($share, 1),
                    'origin' => $row->originName,
                ],
                (int) round($share * 1.2),
                sprintf('%.0f%%', round($share, 0)),
            );

            break;
        }
    }
}
