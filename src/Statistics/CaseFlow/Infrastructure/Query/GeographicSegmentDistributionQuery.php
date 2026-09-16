<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\ClinicalIndicatorDefinition;
use App\Statistics\Application\Mapping\ClinicalIndicatorDefinitions;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentCategoryCount;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use Doctrine\DBAL\Connection;

final readonly class GeographicSegmentDistributionQuery
{
    /** @var list<string> */
    private const array EXTENDED_FEATURE_BUCKETS = ['shock', 'pregnancy', 'work_accident'];

    public function __construct(
        private Connection $connection,
        private ProjectionFeatureQuery $projectionFeatureQuery,
    ) {
    }

    /**
     * @return array<int, GeographicSegmentCategoryCount>
     */
    public function fetchUrgencyCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        $emergency = AllocationStatsUrgencyProjectionCode::Emergency->value;
        $inpatient = AllocationStatsUrgencyProjectionCode::Inpatient->value;
        $outpatient = AllocationStatsUrgencyProjectionCode::Outpatient->value;

        $row = $this->fetchFilterRow(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
            static fn (?string $segmentPredicate): string => implode(",\n    ", [
                self::dualCountSql(sprintf('asp.urgency_code = %d', $emergency), $segmentPredicate, 'urgency_'.$emergency),
                self::dualCountSql(sprintf('asp.urgency_code = %d', $inpatient), $segmentPredicate, 'urgency_'.$inpatient),
                self::dualCountSql(sprintf('asp.urgency_code = %d', $outpatient), $segmentPredicate, 'urgency_'.$outpatient),
            ]),
        );

        return [
            $emergency => $this->countsFromRow($row, 'urgency_'.$emergency),
            $inpatient => $this->countsFromRow($row, 'urgency_'.$inpatient),
            $outpatient => $this->countsFromRow($row, 'urgency_'.$outpatient),
        ];
    }

    /**
     * @return array<int, GeographicSegmentCategoryCount>
     */
    public function fetchGenderCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        $male = AllocationStatsGenderProjectionCode::Male->value;
        $female = AllocationStatsGenderProjectionCode::Female->value;
        $other = AllocationStatsGenderProjectionCode::Other->value;

        $row = $this->fetchFilterRow(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
            static fn (?string $segmentPredicate): string => implode(",\n    ", [
                self::dualCountSql(sprintf('asp.gender_code = %d', $male), $segmentPredicate, 'gender_'.$male),
                self::dualCountSql(sprintf('asp.gender_code = %d', $female), $segmentPredicate, 'gender_'.$female),
                self::dualCountSql(sprintf('asp.gender_code = %d', $other), $segmentPredicate, 'gender_'.$other),
            ]),
        );

        return [
            $male => $this->countsFromRow($row, 'gender_'.$male),
            $female => $this->countsFromRow($row, 'gender_'.$female),
            $other => $this->countsFromRow($row, 'gender_'.$other),
        ];
    }

    /**
     * @return array<string, GeographicSegmentCategoryCount>
     */
    public function fetchAgeCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        $context = $this->scopeContext(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
        );
        if (null === $context) {
            return [];
        }

        [$where, $params, $types, $segmentPredicate] = $context;
        $bucketSql = StatisticsAgeGroupBucketSql::CASE_EXPRESSION;
        $segmentCountSql = null === $segmentPredicate
            ? 'COUNT(*)::int'
            : "COUNT(*) FILTER (WHERE {$segmentPredicate})::int";

        $sql = <<<SQL
SELECT {$bucketSql} AS bucket_key,
    COUNT(*)::int AS reference_count,
    {$segmentCountSql} AS segment_count
FROM allocation_stats_projection asp
WHERE {$where}
GROUP BY {$bucketSql}
SQL;

        $counts = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $referenceCount = (int) $row['reference_count'];
            $counts[(string) $row['bucket_key']] = new GeographicSegmentCategoryCount(
                (int) $row['segment_count'],
                $referenceCount,
            );
        }

        return $counts;
    }

    /**
     * @return array<string, GeographicSegmentCategoryCount>
     */
    public function fetchResourceCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        return $this->fetchIndicatorCounts(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
            ClinicalIndicatorDefinitions::forDimension(ClinicalIndicatorDefinitions::DIMENSION_RESOURCES),
        );
    }

    /**
     * @return array<string, GeographicSegmentCategoryCount>
     */
    public function fetchClinicalFeatureCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
    ): array {
        return $this->fetchIndicatorCounts(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
            ClinicalIndicatorDefinitions::forDimension(ClinicalIndicatorDefinitions::DIMENSION_FEATURES),
        );
    }

    /**
     * @param list<ClinicalIndicatorDefinition> $definitions
     *
     * @return array<string, GeographicSegmentCategoryCount>
     */
    private function fetchIndicatorCounts(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId,
        ?StatisticsDrawerFilter $drawerFilter,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch,
        array $definitions,
    ): array {
        $hasExtended = $this->projectionFeatureQuery->hasExtendedClinicalFeatureColumns();
        $row = $this->fetchFilterRow(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
            fn (?string $segmentPredicate): string => implode(
                ",\n    ",
                array_map(
                    fn (ClinicalIndicatorDefinition $definition): string => $this->indicatorCountSql(
                        $definition,
                        $segmentPredicate,
                        $hasExtended,
                    ),
                    $definitions,
                ),
            ),
        );

        $counts = [];
        foreach ($definitions as $definition) {
            $counts[$definition->bucketKey] = $this->countsFromRow($row, $definition->bucketKey);
        }

        return $counts;
    }

    private function indicatorCountSql(
        ClinicalIndicatorDefinition $definition,
        ?string $segmentPredicate,
        bool $hasExtended,
    ): string {
        if (!$hasExtended && \in_array($definition->bucketKey, self::EXTENDED_FEATURE_BUCKETS, true)) {
            return sprintf(
                '0::int AS %1$s_reference,'.PHP_EOL.'    0::int AS %1$s_segment',
                $definition->bucketKey,
            );
        }

        return self::dualCountSql('asp.'.$definition->matchSqlCondition, $segmentPredicate, $definition->bucketKey);
    }

    /**
     * @param callable(string|null): string $selectList
     *
     * @return array<string, mixed>
     */
    private function fetchFilterRow(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId,
        ?StatisticsDrawerFilter $drawerFilter,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch,
        callable $selectList,
    ): array {
        $context = $this->scopeContext(
            $from,
            $toExclusive,
            $scope,
            $segment,
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
        );
        if (null === $context) {
            return [];
        }

        [$where, $params, $types, $segmentPredicate] = $context;
        $sql = <<<SQL
SELECT
    {$selectList($segmentPredicate)}
FROM allocation_stats_projection asp
WHERE {$where}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);

        return false === $row ? [] : $row;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, \Doctrine\DBAL\ArrayParameterType>, 3: ?string}|null
     */
    private function scopeContext(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?GeographicSegment $segment,
        ?int $originStateId,
        ?StatisticsDrawerFilter $drawerFilter,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch,
    ): ?array {
        if (CaseFlowSqlFilter::isImpossibleScope($scope, $originStateId)) {
            return null;
        }

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere(
            $from,
            $toExclusive,
            $scope,
            'asp',
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
        );

        $segmentPredicate = null;
        if ($segment instanceof GeographicSegment) {
            [$segmentPredicate, $segmentParams] = $segment->sqlPredicate('asp');
            $params = [...$params, ...$segmentParams];
        }

        return [$where, $params, $types, $segmentPredicate];
    }

    private static function dualCountSql(string $condition, ?string $segmentPredicate, string $alias): string
    {
        $referenceSql = "COUNT(*) FILTER (WHERE {$condition})::int AS {$alias}_reference";
        if (null === $segmentPredicate) {
            return $referenceSql.",\n    COUNT(*) FILTER (WHERE {$condition})::int AS {$alias}_segment";
        }

        return $referenceSql.",\n    COUNT(*) FILTER (WHERE ({$condition}) AND ({$segmentPredicate}))::int AS {$alias}_segment";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function countsFromRow(array $row, string $alias): GeographicSegmentCategoryCount
    {
        return new GeographicSegmentCategoryCount(
            (int) ($row[$alias.'_segment'] ?? 0),
            (int) ($row[$alias.'_reference'] ?? 0),
        );
    }
}
