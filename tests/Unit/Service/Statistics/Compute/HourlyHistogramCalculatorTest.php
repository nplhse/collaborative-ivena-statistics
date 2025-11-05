<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Compute;

use App\Model\Scope;
use App\Service\Statistics\Compute\HourlyHistogramCalculator;
use App\Service\Statistics\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class HourlyHistogramCalculatorTest extends TestCase
{
    public function testSupportsAlwaysReturnsTrue(): void
    {
        $db = $this->createMock(Connection::class);
        $calc = new HourlyHistogramCalculator($db);

        $scope = new Scope('hospital', '123', Period::ALL, Period::ALL_ANCHOR_DATE);
        self::assertTrue($calc->supports($scope));
    }

    public function testCalculateWithAllGranularityOverwritesResultWith24Zeros(): void
    {
        $importScope = new Scope('hospital', '5', Period::ALL, Period::ALL_ANCHOR_DATE);

        $capturedSelectSql = null;
        $capturedSelectParams = null;
        $capturedUpsertSql = null;
        $capturedUpsertParams = null;

        $db = $this->createMock(Connection::class);

        // 1) SELECT … fetchAssociative
        $db->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                self::callback(function (string $sql) use (&$capturedSelectSql) {
                    $capturedSelectSql = $sql;

                    // Must include key fragments from HourlyHistogramCalculator:
                    self::assertStringContainsString("EXTRACT(HOUR FROM (arrival_at AT TIME ZONE 'Europe/Berlin'))::int AS h", $sql, 'Missing hour extraction with timezone.');
                    self::assertStringContainsString('FROM allocation', $sql, 'Must select from allocation.');
                    // For ALL granularity, ScopePeriodSql::buildPeriodExpr returns TRUE
                    self::assertStringContainsString('WHERE hospital_id = :scope_id::int', $sql, 'Missing hospital scope filter.');
                    self::assertStringContainsString('AND TRUE', $sql, 'ALL granularity should contribute TRUE in WHERE.');

                    return true;
                }),
                self::callback(function (array $params) use (&$capturedSelectParams) {
                    $capturedSelectParams = $params;
                    self::assertSame(['scope_id' => '5'], $params, 'Expected only scope_id param for ALL granularity.');

                    return true;
                })
            )
            // Any non-false array should trigger the branch that overwrites with zeros:
            ->willReturn(['hours_count' => '{1,2,3}']);

        // 2) UPSERT executeStatement
        $db->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::callback(function (string $sql) use (&$capturedUpsertSql) {
                    $capturedUpsertSql = $sql;

                    self::assertStringContainsString('INSERT INTO agg_allocations_hourly', $sql);
                    self::assertStringContainsString('ON CONFLICT (scope_type, scope_id, period_gran, period_key)', $sql);
                    self::assertStringContainsString('DO UPDATE SET', $sql);
                    self::assertStringContainsString('computed_at = now()', $sql);

                    return true;
                }),
                self::callback(function (array $params) use (&$capturedUpsertParams, $importScope) {
                    $capturedUpsertParams = $params;

                    // Expect 24 zeros as a PG array literal string
                    $expectedZeros = '{'.implode(',', array_fill(0, 24, 0)).'}';

                    self::assertSame($importScope->scopeType, $params['scope_type']);
                    self::assertSame($importScope->scopeId, $params['scope_id']);
                    self::assertSame($importScope->granularity, $params['period_gran']);
                    self::assertSame($importScope->periodKey, $params['period_key']);
                    self::assertSame($expectedZeros, $params['hours_count'], 'hours_count must be 24 zeros string when select returned non-false.');

                    return true;
                })
            )
            ->willReturn(1);

        $calc = new HourlyHistogramCalculator($db);
        $calc->calculate($importScope);
    }

    public function testCalculateWhenSelectReturnsFalseSetsHoursCountToZero(): void
    {
        $scope = new Scope('hospital', '123', Period::DAY, '2025-11-01');

        $db = $this->createMock(Connection::class);

        // 1) SELECT returns false → code sets ['hours_count' => 0]
        $db->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                self::callback(function (string $sql) {
                    // Must include period filter for DAY and scope filter for hospital id
                    self::assertStringContainsString('WHERE hospital_id = :scope_id::int', $sql);
                    self::assertStringContainsString('AND period_day(arrival_at) = :period_key::date', $sql);

                    return true;
                }),
                self::callback(function (array $params) {
                    // For DAY, expect both scope_id and period_key
                    self::assertSame(['scope_id' => '123', 'period_key' => '2025-11-01'], $params);

                    return true;
                })
            )
            ->willReturn(false);

        // 2) UPSERT should receive hours_count = 0
        $db->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::anything(),
                self::callback(function (array $params) {
                    self::assertSame('hospital', $params['scope_type']);
                    self::assertSame('123', $params['scope_id']);
                    self::assertSame(Period::DAY, $params['period_gran']);
                    self::assertSame('2025-11-01', $params['period_key']);
                    self::assertSame(0, $params['hours_count'], 'hours_count must be integer 0 when select returned false.');

                    return true;
                })
            )
            ->willReturn(1);

        $calc = new HourlyHistogramCalculator($db);
        $calc->calculate($scope);
    }
}
