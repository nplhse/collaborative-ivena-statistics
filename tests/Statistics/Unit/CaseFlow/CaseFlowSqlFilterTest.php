<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowSqlFilter;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\TestCase;

final class CaseFlowSqlFilterTest extends TestCase
{
    public function testBuildsPeriodHospitalAndAliasFilters(): void
    {
        $from = new \DateTimeImmutable('2025-01-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2026-01-01 00:00:00');
        $scope = new StatisticsScopeCriteria([10, 20], [1], [2]);

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere(
            $from,
            $toExclusive,
            $scope,
            'asp',
        );

        self::assertStringContainsString('asp.created_at >= :from', $where);
        self::assertStringContainsString('asp.created_at < :to_exclusive', $where);
        self::assertStringContainsString('asp.hospital_id IN (:hospital_ids)', $where);
        self::assertStringContainsString('asp.hospital_location_code IN (:location_codes)', $where);
        self::assertStringContainsString('asp.hospital_tier_code IN (:tier_codes)', $where);
        self::assertSame('2025-01-01 00:00:00', $params['from']);
        self::assertSame('2026-01-01 00:00:00', $params['to_exclusive']);
        self::assertSame([10, 20], $params['hospital_ids']);
        self::assertSame([1], $params['location_codes']);
        self::assertSame([2], $params['tier_codes']);
        self::assertSame(ArrayParameterType::INTEGER, $types['hospital_ids']);
        self::assertSame(ArrayParameterType::INTEGER, $types['location_codes']);
        self::assertSame(ArrayParameterType::INTEGER, $types['tier_codes']);
    }

    public function testEmptyHospitalScopeAddsImpossiblePredicate(): void
    {
        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere(
            null,
            null,
            new StatisticsScopeCriteria([]),
        );

        self::assertStringContainsString('1 = 0', $where);
        self::assertSame([], $params);
        self::assertSame([], $types);
    }

    public function testPublicScopeWithoutPeriodBoundsUsesTruePredicate(): void
    {
        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere(
            null,
            null,
            StatisticsScopeCriteria::public(),
        );

        self::assertSame('1 = 1', $where);
        self::assertSame([], $params);
        self::assertSame([], $types);
    }
}
