<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardCriteria;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardHeader;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardResult;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSliceQuery;

final readonly class IndicationDashboardService
{
    public function __construct(
        private IndicationNormalizedRepository $indicationRepository,
        private IndicationDashboardMetricsQuery $metricsQuery,
        private IndicationDashboardSliceQuery $sliceQuery,
        private IndicationInsightEngine $insightEngine,
        private IndicationDashboardAssembler $assembler,
    ) {
    }

    public function build(IndicationDashboardCriteria $criteria): ?IndicationDashboardResult
    {
        $indication = $this->indicationRepository->find($criteria->indicationId);
        if (!$indication instanceof IndicationNormalized) {
            return null;
        }

        $subject = new InsightSubject(
            InsightDimensionKey::Indications,
            $criteria->indicationId,
            $indication->getName() ?? '',
            InsightPopulationFilter::indications([$criteria->indicationId]),
            $indication->getCode(),
            $indication->getPublicId()?->toRfc4122(),
        );

        return $this->buildForInsight($subject, $criteria->scope, $criteria->period, $criteria->timeSeriesGrain);
    }

    public function buildForSubject(
        IndicationSubject $subject,
        StatisticsScopeCriteria $scope,
        StatisticsPeriodBounds $period,
        TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
    ): ?IndicationDashboardResult {
        $dimension = IndicationSubjectType::Group === $subject->type
            ? InsightDimensionKey::IndicationGroups
            : InsightDimensionKey::Indications;

        $code = IndicationSubjectType::Single === $subject->type && 1 === \count($subject->indicationIds)
            ? $this->indicationRepository->find($subject->indicationIds[0])?->getCode()
            : null;

        return $this->buildForInsight(
            new InsightSubject(
                $dimension,
                $subject->id,
                $subject->label,
                InsightPopulationFilter::indications($subject->indicationIds),
                $code,
            ),
            $scope,
            $period,
            $timeSeriesGrain,
        );
    }

    /**
     * @param list<string> $disabledInsightIds
     */
    public function buildForInsight(
        InsightSubject $subject,
        StatisticsScopeCriteria $scope,
        StatisticsPeriodBounds $period,
        TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
        array $disabledInsightIds = [],
    ): ?IndicationDashboardResult {
        if ($subject->population->isEmpty()) {
            return null;
        }

        $from = $period->from;
        $toExclusive = $period->toExclusive;

        $metrics = $this->metricsQuery->fetch($subject->population, $from, $toExclusive, $scope);
        $slice = $this->sliceQuery->fetch($subject->population, $from, $toExclusive, $scope, $timeSeriesGrain);

        $total = $metrics->totalIndication;

        return new IndicationDashboardResult(
            new IndicationDashboardHeader(
                $subject->id,
                $subject->label,
                $subject->code,
                $total,
                $subject->publicId,
                $subject->dimension->value,
                'stats.insights.dimension.'.$this->dimensionTranslationSuffix($subject->dimension).'.label',
            ),
            $this->assembler->buildSummaryDeck($slice->genderCounts, $metrics),
            $this->insightEngine->build($metrics, $disabledInsightIds),
            $this->assembler->buildTimeSeries($slice->monthlyRows, $timeSeriesGrain, $period),
            $this->assembler->buildDayTimeHeatmap($slice->dayTimeHeatmapCells),
            $this->assembler->buildShiftHeatmap($slice->shiftHeatmapCells),
            $this->assembler->buildResourcesDistribution($metrics),
            $this->assembler->buildTransportDistribution($metrics),
            $this->assembler->buildTransportTimeDistribution($slice->transportTimeBucketCounts, $total),
            $this->assembler->buildClinicalFeatures($metrics),
            $this->assembler->buildAgeGroupDistribution($slice->ageGroupCounts, $total),
            $metrics->medianAgeIndication,
            $metrics,
        );
    }

    private function dimensionTranslationSuffix(InsightDimensionKey $dimension): string
    {
        return str_replace('-', '_', $dimension->value);
    }
}
