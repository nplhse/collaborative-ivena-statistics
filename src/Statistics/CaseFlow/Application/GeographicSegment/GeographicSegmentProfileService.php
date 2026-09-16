<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\ClinicalIndicatorDefinition;
use App\Statistics\Application\Mapping\ClinicalIndicatorDefinitions;
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

        $groups = $this->groupsForDimension(
            $criteria,
            $segment,
            $dimension,
            $dispatchAreaMatch,
            $metrics->segmentCases,
            $metrics->populationCases,
            !$entireArea,
        );

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
        int $populationCases,
        bool $compareToReference,
    ): array {
        $from = $criteria->period->from;
        $toExclusive = $criteria->period->toExclusive;
        $scope = $criteria->scope;
        $originStateId = $criteria->originStateId();
        $drawerFilter = $criteria->drawerFilter;

        return match ($dimension) {
            GeographicSegmentProfileDimension::Overview => [
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
                        $populationCases,
                        $compareToReference,
                    ),
                ),
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
                        $populationCases,
                        $compareToReference,
                    ),
                ),
            ],
            GeographicSegmentProfileDimension::Age => [
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
                        $populationCases,
                        $compareToReference,
                    ),
                ),
            ],
            GeographicSegmentProfileDimension::Resources => [
                new GeographicSegmentDistributionGroup(
                    'stats.analysis.dimension.resources',
                    $this->indicatorRows(
                        $this->distributionQuery->fetchResourceCounts(
                            $from,
                            $toExclusive,
                            $scope,
                            $segment,
                            $originStateId,
                            $drawerFilter,
                            $dispatchAreaMatch,
                        ),
                        ClinicalIndicatorDefinitions::forDimension(ClinicalIndicatorDefinitions::DIMENSION_RESOURCES),
                        $segmentCases,
                        $populationCases,
                        $compareToReference,
                    ),
                ),
            ],
            GeographicSegmentProfileDimension::Features => [
                new GeographicSegmentDistributionGroup(
                    'stats.analysis.dimension.features',
                    $this->indicatorRows(
                        $this->distributionQuery->fetchClinicalFeatureCounts(
                            $from,
                            $toExclusive,
                            $scope,
                            $segment,
                            $originStateId,
                            $drawerFilter,
                            $dispatchAreaMatch,
                        ),
                        ClinicalIndicatorDefinitions::forDimension(ClinicalIndicatorDefinitions::DIMENSION_FEATURES),
                        $segmentCases,
                        $populationCases,
                        $compareToReference,
                    ),
                ),
            ],
        };
    }

    /**
     * @param array<int, GeographicSegmentCategoryCount> $counts
     *
     * @return list<GeographicSegmentDistributionRow>
     */
    private function urgencyRows(
        array $counts,
        int $segmentTotal,
        int $referenceTotal,
        bool $compareToReference,
    ): array {
        $rows = [];
        foreach (AllocationStatsUrgencyProjectionCode::cases() as $code) {
            $rows[] = GeographicSegmentDistributionRow::fromCategory(
                AllocationUrgency::from($code->value)->label(),
                $counts[$code->value] ?? GeographicSegmentCategoryCount::empty(),
                $segmentTotal,
                $referenceTotal,
                $compareToReference,
                self::URGENCY_BAR_CLASSES[$code->value] ?? 'bg-secondary',
            );
        }

        return $rows;
    }

    /**
     * @param array<int, GeographicSegmentCategoryCount> $counts
     *
     * @return list<GeographicSegmentDistributionRow>
     */
    private function genderRows(
        array $counts,
        int $segmentTotal,
        int $referenceTotal,
        bool $compareToReference,
    ): array {
        $rows = [];
        foreach (AllocationStatsGenderProjectionCode::cases() as $code) {
            $rows[] = GeographicSegmentDistributionRow::fromCategory(
                $code->labelTranslationKey(),
                $counts[$code->value] ?? GeographicSegmentCategoryCount::empty(),
                $segmentTotal,
                $referenceTotal,
                $compareToReference,
                self::GENDER_BAR_CLASSES[$code->value] ?? 'bg-secondary',
            );
        }

        return $rows;
    }

    /**
     * @param array<string, GeographicSegmentCategoryCount> $counts
     *
     * @return list<GeographicSegmentDistributionRow>
     */
    private function ageRows(
        array $counts,
        int $segmentTotal,
        int $referenceTotal,
        bool $compareToReference,
    ): array {
        $rows = [];
        foreach (StatisticsAgeGroupBucketSql::DISPLAY_BUCKET_KEYS as $key) {
            $rows[] = GeographicSegmentDistributionRow::fromCategory(
                'stats.benchmark.age_group.'.$key,
                $counts[$key] ?? GeographicSegmentCategoryCount::empty(),
                $segmentTotal,
                $referenceTotal,
                $compareToReference,
            );
        }

        return $rows;
    }

    /**
     * @param array<string, GeographicSegmentCategoryCount> $counts
     * @param list<ClinicalIndicatorDefinition>             $definitions
     *
     * @return list<GeographicSegmentDistributionRow>
     */
    private function indicatorRows(
        array $counts,
        array $definitions,
        int $segmentTotal,
        int $referenceTotal,
        bool $compareToReference,
    ): array {
        $rows = [];
        foreach ($definitions as $definition) {
            $rows[] = GeographicSegmentDistributionRow::fromCategory(
                $definition->labelTranslationKey,
                $counts[$definition->bucketKey] ?? GeographicSegmentCategoryCount::empty(),
                $segmentTotal,
                $referenceTotal,
                $compareToReference,
            );
        }

        return $rows;
    }
}
