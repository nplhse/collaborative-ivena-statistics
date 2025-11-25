<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Compute;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Compute\HourlyHistogramCalculator;
use App\Statistics\Infrastructure\Util\Period;
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

    public function testCalculateWithAllGranularityPersistsReturnedJson(): void
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

                    // Muss exakt die neue Stunde-Extraktion inkl. Alias 'hour' enthalten
                    self::assertStringContainsString(
                        "EXTRACT(HOUR FROM (arrival_at AT TIME ZONE 'Europe/Berlin'))::int AS hour",
                        $sql,
                        'Missing hour extraction with timezone and alias "hour".'
                    );
                    self::assertStringContainsString('FROM allocation', $sql, 'Must select from allocation.');
                    // Für ALL-Granularität liefert ScopePeriodSql::buildPeriodExpr → TRUE
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
            // Neue Implementierung: Rückgabe kann bereits als Array vorliegen → wird gejson-encoded
            ->willReturn(['hours_count' => ['total' => range(0, 23)]]);

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

                    self::assertSame($importScope->scopeType, $params['scope_type']);
                    self::assertSame($importScope->scopeId, $params['scope_id']);
                    self::assertSame($importScope->granularity, $params['period_gran']);
                    self::assertSame($importScope->periodKey, $params['period_key']);

                    // Erwartet wird ein JSON-String; minimal prüfen wir "total" mit 24 Einträgen
                    self::assertIsString($params['hours_count'], 'hours_count must be a JSON string.');
                    $decoded = json_decode($params['hours_count'], true, 512, JSON_THROW_ON_ERROR);
                    self::assertIsArray($decoded);
                    self::assertArrayHasKey('total', $decoded);
                    self::assertCount(24, $decoded['total'], 'total must contain 24 entries.');

                    return true;
                })
            )
            ->willReturn(1);

        $calc = new HourlyHistogramCalculator($db);
        $calc->calculate($importScope);
    }

    public function testCalculateWhenSelectReturnsFalseSetsHoursCountToZerosJson(): void
    {
        $scope = new Scope('hospital', '123', Period::DAY, '2025-11-01');

        $db = $this->createMock(Connection::class);

        // 1) SELECT returns false → Code baut JSON mit 24 Nullen je Metrik
        $db->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                self::callback(function (string $sql) {
                    // Muss Perioden- und Scope-Filter enthalten
                    self::assertStringContainsString('WHERE hospital_id = :scope_id::int', $sql);
                    self::assertStringContainsString('AND period_day(arrival_at) = :period_key::date', $sql);

                    return true;
                }),
                self::callback(function (array $params) {
                    // Für DAY: scope_id und period_key
                    self::assertSame(['scope_id' => '123', 'period_key' => '2025-11-01'], $params);

                    return true;
                })
            )
            ->willReturn(false);

        // 2) UPSERT: hours_count = JSON (alle Metriken → 24 Nullen)
        $db->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::anything(),
                self::callback(function (array $params) {
                    self::assertSame('hospital', $params['scope_type']);
                    self::assertSame('123', $params['scope_id']);
                    self::assertSame(Period::DAY, $params['period_gran']);
                    self::assertSame('2025-11-01', $params['period_key']);

                    self::assertIsString($params['hours_count'], 'hours_count should be a JSON string when select returned false.');
                    $data = json_decode($params['hours_count'], true, 512, JSON_THROW_ON_ERROR);

                    $metrics = [
                        'total', 'gender_m', 'gender_w', 'gender_d', 'gender_u',
                        'urg_1', 'urg_2', 'urg_3',
                        'cathlab_required', 'resus_required',
                        'is_cpr', 'is_ventilated', 'is_shock', 'is_pregnant', 'with_physician', 'infectious',
                    ];
                    foreach ($metrics as $m) {
                        self::assertArrayHasKey($m, $data, "Missing metric '$m' in zeros JSON.");
                        self::assertCount(24, $data[$m], "Metric '$m' must have 24 entries.");
                        self::assertSame(array_fill(0, 24, 0), $data[$m], "Metric '$m' must be 24 zeros.");
                    }

                    return true;
                })
            )
            ->willReturn(1);

        $calc = new HourlyHistogramCalculator($db);
        $calc->calculate($scope);
    }
}
