<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application;

use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentAssignmentsCriteria;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentAssignmentsResult;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentHeatmapData;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentNamedCardView;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentNamedRow;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentTimeSeries;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\ClosedDepartmentMetricsQuery;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\ClosedDepartmentSliceQuery;

final readonly class ClosedDepartmentAssignmentsService
{
    public function __construct(
        private ClosedDepartmentMetricsQuery $metricsQuery,
        private ClosedDepartmentSliceQuery $sliceQuery,
        private ClosedDepartmentAssignmentsAssembler $assembler,
    ) {
    }

    public function buildSummary(ClosedDepartmentAssignmentsCriteria $criteria): ClosedDepartmentAssignmentsResult
    {
        $from = $criteria->period->from;
        $toExclusive = $criteria->period->toExclusive;
        $metrics = $this->metricsQuery->fetchKpis($from, $toExclusive, $criteria->scope);
        $hasClosedCases = $metrics->closedCount > 0;
        if (!$hasClosedCases) {
            return ClosedDepartmentAssignmentsResult::forSummary(
                $this->assembler->kpis($metrics),
                new ClosedDepartmentTimeSeries([], [], []),
                new ClosedDepartmentHeatmapData([], [], [], 0),
                $this->assembler->exploreUrl($criteria),
                false,
            );
        }

        $slice = $this->sliceQuery->fetchSummary($from, $toExclusive, $criteria->scope, $criteria->timeSeriesGrain);

        return ClosedDepartmentAssignmentsResult::forSummary(
            $this->assembler->kpis($metrics),
            $this->assembler->timeSeries($slice, $criteria->timeSeriesGrain, $criteria->period),
            $this->assembler->weekdayDayTimeHeatmap($slice),
            $this->assembler->exploreUrl($criteria),
            true,
        );
    }

    /**
     * @return list<ClosedDepartmentNamedCardView>
     */
    public function buildRankingCards(
        ClosedDepartmentAssignmentsCriteria $criteria,
        int $closedCount,
    ): array {
        $slice = $this->sliceQuery->fetchRankings(
            $criteria->period->from,
            $criteria->period->toExclusive,
            $criteria->scope,
            $criteria->timeSeriesGrain,
        );

        $cards = [];
        foreach (ClosedDepartmentNamedCard::rankingCards() as $card) {
            $cards[] = new ClosedDepartmentNamedCardView(
                $card,
                $this->assembler->namedRows(
                    $card->rowsFromSlice($slice),
                    $closedCount,
                    $criteria,
                    $card->exploreQueryKey(),
                ),
            );
        }

        return $cards;
    }

    /**
     * @return list<ClosedDepartmentNamedRow>
     */
    public function buildNamedCard(
        ClosedDepartmentAssignmentsCriteria $criteria,
        ClosedDepartmentNamedCard $card,
        int $closedCount,
    ): array {
        $slice = $this->sliceQuery->fetchKinds(
            $criteria->period->from,
            $criteria->period->toExclusive,
            $criteria->scope,
            [$card->sliceKind()],
            $criteria->timeSeriesGrain,
        );

        return $this->assembler->namedRows(
            $card->rowsFromSlice($slice),
            $closedCount,
            $criteria,
            $card->exploreQueryKey(),
        );
    }

    public function buildDetails(ClosedDepartmentAssignmentsCriteria $criteria): ClosedDepartmentAssignmentsResult
    {
        $from = $criteria->period->from;
        $toExclusive = $criteria->period->toExclusive;
        $metrics = $this->metricsQuery->fetch($from, $toExclusive, $criteria->scope);
        $slice = $this->sliceQuery->fetchDetails($from, $toExclusive, $criteria->scope, $criteria->timeSeriesGrain);

        return ClosedDepartmentAssignmentsResult::forDetails(
            $this->assembler->kpis($metrics),
            $this->assembler->gender($metrics),
            $this->assembler->urgency($metrics, $criteria),
            $this->assembler->resources($metrics, $criteria),
            $this->assembler->clinicalFeatures($metrics, $criteria),
            $this->assembler->transport($metrics, $slice),
            [],
            $this->assembler->exploreUrl($criteria),
            $metrics->closedCount > 0,
        );
    }
}
