<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacyPolicy;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowDispatchAreaMatch;
use App\Statistics\CaseFlow\Infrastructure\Query\GeographicSegmentDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\GeographicSegmentMetricsQuery;

final readonly class GeographicSegmentProfileService
{
    public const string ENTIRE_AREA_LABEL = 'stats.case_flow.segment.entire_area';

    /** @var array<int, string> */
    private const array URGENCY_BAR_CLASSES = [
        1 => 'bg-red',
        2 => 'bg-yellow',
        3 => 'bg-green',
    ];

    /** @var array<int, string> */
    private const array GENDER_BAR_CLASSES = [
        1 => 'bg-primary',
        2 => 'bg-pink',
        3 => 'bg-purple',
    ];

    /** @var array<string, string> */
    private const array RESOURCE_LABELS = [
        'resus' => 'statistics.distribution.dim.requires_resus',
        'cathlab' => 'statistics.distribution.dim.requires_cathlab',
        'with_physician' => 'statistics.distribution.dim.is_with_physician',
        'cpr' => 'statistics.distribution.dim.is_cpr',
        'ventilation' => 'statistics.distribution.dim.is_ventilated',
        'shock' => 'stats.analysis.feature.is_shock',
    ];

    public function __construct(
        private GeographicSegmentMetricsQuery $metricsQuery,
        private GeographicSegmentDistributionQuery $distributionQuery,
    ) {
    }

    public function build(
        CaseFlowCriteria $criteria,
        GeographicSegmentCatalog $catalog,
        ?GeographicSegment $segment,
        GeographicSegmentProfileDimension $dimension,
    ): GeographicSegmentProfileView {
        $dispatchAreaMatch = $criteria->isDispatchAreaScope()
            ? CaseFlowDispatchAreaMatch::Catchment
            : CaseFlowDispatchAreaMatch::Related;

        $metrics = $this->metricsQuery->fetch(
            $criteria->period->from,
            $criteria->period->toExclusive,
            $criteria->scope,
            $segment,
            $criteria->originStateId(),
            $criteria->drawerFilter,
            $dispatchAreaMatch,
        );

        $entireArea = !$segment instanceof GeographicSegment;
        $option = $segment instanceof GeographicSegment ? $catalog->find($segment) : null;
        $suppressed = $metrics->segmentCases < CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL;
        if (!$segment instanceof GeographicSegment) {
            $label = self::ENTIRE_AREA_LABEL;
            $translateLabel = true;
        } elseif ($option instanceof GeographicSegmentOption) {
            $label = $option->label;
            $translateLabel = $option->translateLabel;
        } else {
            $label = $segment->labelTranslationKey();
            $translateLabel = null !== $label;
        }
        $share = !$entireArea && $metrics->populationCases > 0
            ? round(100.0 * (float) $metrics->segmentCases / (float) $metrics->populationCases, 1)
            : null;
        $showMedianTransport = $entireArea || ($segment instanceof GeographicSegment && !$segment->isTravelTimeBand());

        if ($suppressed) {
            return new GeographicSegmentProfileView(
                $segment,
                $label,
                $translateLabel,
                true,
                true,
                $metrics->segmentCases,
                $metrics->populationCases,
                null,
                null,
                false,
                $dimension,
                [],
            );
        }

        $groups = $this->groupsForDimension($criteria, $segment, $dimension, $dispatchAreaMatch, $metrics->segmentCases);

        return new GeographicSegmentProfileView(
            $segment,
            $label,
            $translateLabel,
            true,
            false,
            $metrics->segmentCases,
            $metrics->populationCases,
            $share,
            $showMedianTransport ? $metrics->medianTransportMinutes : null,
            $showMedianTransport,
            $dimension,
            $groups,
        );
    }

    /**
     * @return list<GeographicSegmentDistributionGroup>
     */
    private function groupsForDimension(
        CaseFlowCriteria $criteria,
        ?GeographicSegment $segment,
        GeographicSegmentProfileDimension $dimension,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch,
        int $segmentCases,
    ): array {
        $from = $criteria->period->from;
        $toExclusive = $criteria->period->toExclusive;
        $scope = $criteria->scope;
        $originStateId = $criteria->originStateId();
        $drawerFilter = $criteria->drawerFilter;

        return match ($dimension) {
            GeographicSegmentProfileDimension::Overview => [],
            GeographicSegmentProfileDimension::Urgency => [
                new GeographicSegmentDistributionGroup(
                    'stats.case_flow.segment.group.urgency',
                    $this->urgencyRows(
                        $this->distributionQuery->fetchUrgencyCounts(
                            $from,
                            $toExclusive,
                            $scope,
                            $segment,
                            $originStateId,
                            $drawerFilter,
                            $dispatchAreaMatch,
                        ),
                        $segmentCases,
                    ),
                ),
            ],
            GeographicSegmentProfileDimension::Demographics => [
                new GeographicSegmentDistributionGroup(
                    'stats.case_flow.segment.group.gender',
                    $this->genderRows(
                        $this->distributionQuery->fetchGenderCounts(
                            $from,
                            $toExclusive,
                            $scope,
                            $segment,
                            $originStateId,
                            $drawerFilter,
                            $dispatchAreaMatch,
                        ),
                        $segmentCases,
                    ),
                ),
                new GeographicSegmentDistributionGroup(
                    'stats.case_flow.segment.group.age',
                    $this->ageRows(
                        $this->distributionQuery->fetchAgeCounts(
                            $from,
                            $toExclusive,
                            $scope,
                            $segment,
                            $originStateId,
                            $drawerFilter,
                            $dispatchAreaMatch,
                        ),
                        $segmentCases,
                    ),
                ),
            ],
            GeographicSegmentProfileDimension::Resources => [
                new GeographicSegmentDistributionGroup(
                    'stats.case_flow.segment.group.resources',
                    $this->resourceRows(
                        $this->distributionQuery->fetchResourceCounts(
                            $from,
                            $toExclusive,
                            $scope,
                            $segment,
                            $originStateId,
                            $drawerFilter,
                            $dispatchAreaMatch,
                        ),
                        $segmentCases,
                    ),
                ),
            ],
        };
    }

    /**
     * @param array<int, int> $counts
     *
     * @return list<GeographicSegmentDistributionRow>
     */
    private function urgencyRows(array $counts, int $total): array
    {
        $rows = [];
        foreach (AllocationStatsUrgencyProjectionCode::cases() as $code) {
            $count = $counts[$code->value] ?? 0;
            $rows[] = new GeographicSegmentDistributionRow(
                AllocationUrgency::from($code->value)->label(),
                $count,
                $this->percent($count, $total),
                self::URGENCY_BAR_CLASSES[$code->value] ?? 'bg-secondary',
            );
        }

        return $rows;
    }

    /**
     * @param array<int, int> $counts
     *
     * @return list<GeographicSegmentDistributionRow>
     */
    private function genderRows(array $counts, int $total): array
    {
        $rows = [];
        foreach (AllocationStatsGenderProjectionCode::cases() as $code) {
            $count = $counts[$code->value] ?? 0;
            $rows[] = new GeographicSegmentDistributionRow(
                $code->labelTranslationKey(),
                $count,
                $this->percent($count, $total),
                self::GENDER_BAR_CLASSES[$code->value] ?? 'bg-secondary',
            );
        }

        return $rows;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<GeographicSegmentDistributionRow>
     */
    private function ageRows(array $counts, int $total): array
    {
        $rows = [];
        foreach (StatisticsAgeGroupBucketSql::DISPLAY_BUCKET_KEYS as $key) {
            $count = $counts[$key] ?? 0;
            $rows[] = new GeographicSegmentDistributionRow(
                'stats.benchmark.age_group.'.$key,
                $count,
                $this->percent($count, $total),
            );
        }

        return $rows;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<GeographicSegmentDistributionRow>
     */
    private function resourceRows(array $counts, int $total): array
    {
        $rows = [];
        foreach (self::RESOURCE_LABELS as $key => $labelKey) {
            $count = $counts[$key] ?? 0;
            $rows[] = new GeographicSegmentDistributionRow(
                $labelKey,
                $count,
                $this->percent($count, $total),
            );
        }

        return $rows;
    }

    private function percent(int $count, int $total): float
    {
        return $total > 0 ? round(100.0 * (float) $count / (float) $total, 1) : 0.0;
    }
}
