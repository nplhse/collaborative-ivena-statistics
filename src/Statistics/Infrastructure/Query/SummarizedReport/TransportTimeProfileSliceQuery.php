<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\SummarizedReport;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileSliceData;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\TransportTimeProfileSliceQueryInterface;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\Infrastructure\Query\ProjectionDrawerFilterSql;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for TransportTimeProfileSliceQueryInterface. */
#[AsAlias(TransportTimeProfileSliceQueryInterface::class)]
final readonly class TransportTimeProfileSliceQuery implements TransportTimeProfileSliceQueryInterface
{
    private const int TOP_LIMIT = 5;

    public function __construct(
        private Connection $connection,
        private GenericAnalysisScopeSqlFilter $scopeSqlFilter,
        private ProjectionDrawerFilterSql $drawerFilterSql,
    ) {
    }

    #[\Override]
    public function fetch(
        StatisticsScopeCriteria $scope,
        StatisticsPeriodBounds $period,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): TransportTimeProfileSliceData {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return TransportTimeProfileSliceData::empty();
        }

        [$scopeConditions, $params] = $this->scopeSqlFilter->applyScopeAndPeriod($scope, $period);
        $types = $this->scopeSqlFilter->parameterTypes($params);

        if ($drawerFilter instanceof StatisticsDrawerFilter && $drawerFilter->isActive()) {
            [$drawerConditions, $drawerParams] = $this->drawerFilterSql->apply($drawerFilter);
            $scopeConditions = [...$scopeConditions, ...$drawerConditions];
            $params = [...$params, ...$drawerParams];
        }

        $where = implode(' AND ', $scopeConditions);
        $bucketCase = StatisticsTransportTimeBucketSql::CASE_EXPRESSION;
        $topLimit = self::TOP_LIMIT;

        $sql = <<<SQL
WITH slice AS (
    SELECT
        {$bucketCase} AS transport_bucket,
        gender_code,
        urgency_code,
        department_id,
        speciality_id,
        indication_normalized_id,
        is_with_physician,
        requires_resus,
        requires_cathlab,
        transport_type_code
    FROM allocation_stats_projection
    WHERE {$where}
)
SELECT 'unknown_volume' AS slice_kind, 'unknown' AS dim1, NULL AS dim2, COUNT(*)::int AS count
FROM slice
WHERE transport_bucket = 'unknown'
UNION ALL
SELECT 'volume', transport_bucket, NULL, COUNT(*)::int
FROM slice
WHERE transport_bucket <> 'unknown'
GROUP BY transport_bucket
UNION ALL
SELECT 'gender', transport_bucket, COALESCE(gender_code::text, 'unknown'), COUNT(*)::int
FROM slice
WHERE transport_bucket <> 'unknown'
GROUP BY transport_bucket, gender_code
UNION ALL
SELECT 'urgency', transport_bucket, urgency_code::text, COUNT(*)::int
FROM slice
WHERE transport_bucket <> 'unknown'
GROUP BY transport_bucket, urgency_code
UNION ALL
SELECT 'physician', transport_bucket, CASE WHEN is_with_physician IS TRUE THEN '1' ELSE '0' END, COUNT(*)::int
FROM slice
WHERE transport_bucket <> 'unknown'
GROUP BY transport_bucket, CASE WHEN is_with_physician IS TRUE THEN '1' ELSE '0' END
UNION ALL
SELECT 'resus', transport_bucket, CASE WHEN requires_resus IS TRUE THEN '1' ELSE '0' END, COUNT(*)::int
FROM slice
WHERE transport_bucket <> 'unknown'
GROUP BY transport_bucket, CASE WHEN requires_resus IS TRUE THEN '1' ELSE '0' END
UNION ALL
SELECT 'cathlab', transport_bucket, CASE WHEN requires_cathlab IS TRUE THEN '1' ELSE '0' END, COUNT(*)::int
FROM slice
WHERE transport_bucket <> 'unknown'
GROUP BY transport_bucket, CASE WHEN requires_cathlab IS TRUE THEN '1' ELSE '0' END
UNION ALL
SELECT 'transport_type', transport_bucket, COALESCE(transport_type_code::text, 'unknown'), COUNT(*)::int
FROM slice
WHERE transport_bucket <> 'unknown'
GROUP BY transport_bucket, transport_type_code
UNION ALL
SELECT 'department', transport_bucket, department_id::text, cnt
FROM (
    SELECT
        transport_bucket,
        department_id,
        COUNT(*)::int AS cnt,
        ROW_NUMBER() OVER (PARTITION BY transport_bucket ORDER BY COUNT(*) DESC, department_id ASC) AS rn
    FROM slice
    WHERE transport_bucket <> 'unknown' AND department_id IS NOT NULL
    GROUP BY transport_bucket, department_id
) ranked_department
WHERE rn <= {$topLimit}
UNION ALL
SELECT 'speciality', transport_bucket, speciality_id::text, cnt
FROM (
    SELECT
        transport_bucket,
        speciality_id,
        COUNT(*)::int AS cnt,
        ROW_NUMBER() OVER (PARTITION BY transport_bucket ORDER BY COUNT(*) DESC, speciality_id ASC) AS rn
    FROM slice
    WHERE transport_bucket <> 'unknown' AND speciality_id IS NOT NULL
    GROUP BY transport_bucket, speciality_id
) ranked_speciality
WHERE rn <= {$topLimit}
UNION ALL
SELECT 'indication', transport_bucket, indication_normalized_id::text, cnt
FROM (
    SELECT
        transport_bucket,
        indication_normalized_id,
        COUNT(*)::int AS cnt,
        ROW_NUMBER() OVER (PARTITION BY transport_bucket ORDER BY COUNT(*) DESC, indication_normalized_id ASC) AS rn
    FROM slice
    WHERE transport_bucket <> 'unknown' AND indication_normalized_id IS NOT NULL
    GROUP BY transport_bucket, indication_normalized_id
) ranked_indication
WHERE rn <= {$topLimit}
SQL;

        /** @var list<array{slice_kind: string, dim1: ?string, dim2: ?string, count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return $this->parseRows($rows);
    }

    /**
     * @param list<array{slice_kind: string, dim1: ?string, dim2: ?string, count: int|string}> $rows
     */
    private function parseRows(array $rows): TransportTimeProfileSliceData
    {
        $unknownCount = 0;
        $volumeByBucket = [];
        $genderByBucket = [];
        $urgencyByBucket = [];
        $physicianByBucket = [];
        $resusByBucket = [];
        $cathlabByBucket = [];
        $transportTypeByBucket = [];
        $departmentsByBucket = [];
        $specialitiesByBucket = [];
        $indicationsByBucket = [];

        foreach ($rows as $row) {
            $count = (int) $row['count'];
            $kind = $row['slice_kind'];
            $dim1 = $row['dim1'] ?? '';
            $dim2 = $row['dim2'];

            match ($kind) {
                'unknown_volume' => $unknownCount = $count,
                'volume' => $volumeByBucket[$dim1] = $count,
                'gender' => $this->addNested($genderByBucket, $dim1, (string) $dim2, $count),
                'urgency' => $this->addNested($urgencyByBucket, $dim1, (string) $dim2, $count),
                'physician' => $this->addNested($physicianByBucket, $dim1, (string) $dim2, $count),
                'resus' => $this->addNested($resusByBucket, $dim1, (string) $dim2, $count),
                'cathlab' => $this->addNested($cathlabByBucket, $dim1, (string) $dim2, $count),
                'transport_type' => $this->addNested($transportTypeByBucket, $dim1, (string) $dim2, $count),
                'department' => $this->addTop($departmentsByBucket, $dim1, (int) $dim2, $count),
                'speciality' => $this->addTop($specialitiesByBucket, $dim1, (int) $dim2, $count),
                'indication' => $this->addTop($indicationsByBucket, $dim1, (int) $dim2, $count),
                default => null,
            };
        }

        $this->sortTopLists($departmentsByBucket);
        $this->sortTopLists($specialitiesByBucket);
        $this->sortTopLists($indicationsByBucket);

        return new TransportTimeProfileSliceData(
            $unknownCount,
            $volumeByBucket,
            $genderByBucket,
            $urgencyByBucket,
            $physicianByBucket,
            $resusByBucket,
            $cathlabByBucket,
            $transportTypeByBucket,
            $departmentsByBucket,
            $specialitiesByBucket,
            $indicationsByBucket,
        );
    }

    /**
     * @param array<string, array<int|string, int>> $target
     */
    private function addNested(array &$target, string $bucket, string $code, int $count): void
    {
        $target[$bucket][$code] = ($target[$bucket][$code] ?? 0) + $count;
    }

    /**
     * @param array<string, list<array{id: int, count: int}>> $target
     */
    private function addTop(array &$target, string $bucket, int $id, int $count): void
    {
        $target[$bucket][] = ['id' => $id, 'count' => $count];
    }

    /**
     * @param array<string, list<array{id: int, count: int}>> $grouped
     */
    private function sortTopLists(array &$grouped): void
    {
        foreach ($grouped as &$items) {
            usort(
                $items,
                static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $a['id'] <=> $b['id'],
            );
        }
    }
}
