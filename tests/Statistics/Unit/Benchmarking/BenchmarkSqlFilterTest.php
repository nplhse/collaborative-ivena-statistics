<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Benchmarking\Infrastructure\Query\BenchmarkSqlFilter;
use PHPUnit\Framework\TestCase;

final class BenchmarkSqlFilterTest extends TestCase
{
    public function testEmptyHospitalScopeReturnsFalsePredicate(): void
    {
        [$sql, $params, $types] = BenchmarkSqlFilter::buildSidePredicate(
            new StatisticsScopeCriteria([]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-01-01')),
            'primary',
        );

        self::assertSame('1 = 0', $sql);
        self::assertSame([], $params);
        self::assertSame([], $types);
    }

    public function testBuildsScopeAndPeriodPredicate(): void
    {
        [$sql, $params, $types] = BenchmarkSqlFilter::buildSidePredicate(
            new StatisticsScopeCriteria([1, 2]),
            new StatisticsPeriodBounds(
                new \DateTimeImmutable('2026-01-01 00:00:00'),
                new \DateTimeImmutable('2026-02-01 00:00:00'),
            ),
            'primary',
        );

        self::assertStringContainsString('created_at >= :primary_from', $sql);
        self::assertStringContainsString('created_at < :primary_to_exclusive', $sql);
        self::assertStringContainsString('hospital_id IN (:primary_hospital_ids)', $sql);
        self::assertSame([1, 2], $params['primary_hospital_ids']);
        self::assertArrayHasKey('primary_hospital_ids', $types);
    }

    public function testUnionWhereCombinesBothSides(): void
    {
        [$sql, $params] = BenchmarkSqlFilter::buildUnionWhere(
            new StatisticsScopeCriteria([1]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-01-01')),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
        );

        self::assertStringContainsString('OR', $sql);
        self::assertArrayHasKey('primary_hospital_ids', $params);
        self::assertArrayNotHasKey('comparison_hospital_ids', $params);
    }
}
