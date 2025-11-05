<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Scheduling;

use App\Model\Scope;
use App\Service\Statistics\Scheduling\DispatchAreaScopeProvider;
use App\Service\Statistics\Scheduling\Sql\ProviderSql;
use App\Service\Statistics\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DispatchAreaScopeProviderTest extends TestCase
{
    public function testProvideForImportYieldsAllExpectedScopesInOrder(): void
    {
        $importId = 42;

        // 1) Distinct dispatch area IDs returned by DB
        $areaIds = [1001, 1002];

        // 2) DB returns for each granularity call (per area)
        $yearKeys = ['2023-01-01']; // 1 key
        $quarterKeys = ['2025-10-01']; // 1 key
        $monthKeys = []; // 0 keys
        $weekKeys = ['2025-10-27']; // 1 key
        $dayKeys = ['2025-11-01', '2025-11-02']; // 2 keys

        // Expected DB calls: 1 for area IDs + (5 granularities * 2 areas) = 11
        $expectedTotalCalls = 1 + (5 * count($areaIds));

        $capturedSql = [];
        $capturedParams = [];

        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly($expectedTotalCalls))
            ->method('fetchFirstColumn')
            ->with(
                self::callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql[] = $sql;

                    return true;
                }),
                self::callback(function (array $params) use (&$capturedParams): bool {
                    $capturedParams[] = $params;

                    return true;
                })
            )
            ->willReturnOnConsecutiveCalls(
                // distinct dispatch_area ids
                $areaIds,
                // granularities for area 1001
                $yearKeys, $quarterKeys, $monthKeys, $weekKeys, $dayKeys,
                // granularities for area 1002
                $yearKeys, $quarterKeys, $monthKeys, $weekKeys, $dayKeys,
            );

        $provider = new DispatchAreaScopeProvider($db);
        /** @var list<Scope> $scopes */
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        // Per area: 1 (ALL) + 1 + 1 + 0 + 1 + 2 = 6 scopes -> total 12
        self::assertCount(12, $scopes, 'Unexpected number of yielded scopes.');

        // ---- Basic semantic checks (no tautological instanceOf) ----
        foreach ($scopes as $scope) {
            self::assertSame('dispatch_area', $scope->scopeType);
            self::assertContains((int) $scope->scopeId, $areaIds, 'Unexpected dispatch_area id.');
            self::assertContains($scope->granularity, Period::allGranularities(), 'Unexpected granularity.');
        }

        // ---- Group by dispatch_area id (safe access) ----
        /** @var array<string, list<Scope>> $byArea */
        $byArea = [];
        foreach ($scopes as $scope) {
            $byArea[$scope->scopeId][] = $scope;
        }

        // First yielded scope per area must be the ALL anchor
        foreach ($areaIds as $aid) {
            $key = (string) $aid;

            /** @var list<Scope> $list */
            $list = $byArea[$key] ?? [];
            self::assertNotEmpty($list, "Empty scope list for dispatch_area id {$key}.");

            $first = reset($list);
            self::assertNotFalse($first, "Failed to get first scope for dispatch_area id {$key}.");
            /* @var Scope $first */

            self::assertSame(Period::ALL, $first->granularity, 'First scope per area must be ALL.');
            self::assertSame(Period::ALL_ANCHOR_DATE, $first->periodKey, 'ALL anchor date mismatch.');
        }

        // ---- Validate the first SQL call retrieves distinct dispatch_area_id ----
        self::assertStringContainsString(
            'SELECT DISTINCT dispatch_area_id',
            $capturedSql[0],
            'First SQL must query distinct dispatch_area_id.'
        );
        self::assertStringContainsString(
            'WHERE import_id = :id AND dispatch_area_id IS NOT NULL',
            $capturedSql[0],
            'First SQL must filter by import id and exclude NULLs.'
        );
        self::assertSame(['id' => $importId], $capturedParams[0], 'First call params must pass import id.');

        // ---- Following calls: for each area, YEAR..DAY in order ----
        $expectedExprs = [
            ProviderSql::periodKeySelect(Period::YEAR),
            ProviderSql::periodKeySelect(Period::QUARTER),
            ProviderSql::periodKeySelect(Period::MONTH),
            ProviderSql::periodKeySelect(Period::WEEK),
            ProviderSql::periodKeySelect(Period::DAY),
        ];

        $granularSqlCalls = array_slice($capturedSql, 1);
        $granularParamCalls = array_slice($capturedParams, 1);

        self::assertCount(5 * count($areaIds), $granularSqlCalls, 'Unexpected number of granular SQL calls.');

        foreach ($areaIds as $idx => $aid) {
            $offset = $idx * 5;
            for ($i = 0; $i < 5; ++$i) {
                $sql = $granularSqlCalls[$offset + $i];
                $params = $granularParamCalls[$offset + $i];

                self::assertStringContainsString(
                    'SELECT DISTINCT '.$expectedExprs[$i].' AS k',
                    $sql,
                    'Call #'.($offset + $i).' must contain the expected period expression.'
                );
                self::assertStringContainsString('FROM allocation', $sql);
                self::assertStringContainsString('WHERE import_id = :id AND dispatch_area_id = :sid', $sql);
                self::assertStringContainsString('ORDER BY k ASC', $sql);

                self::assertSame(['id' => $importId, 'sid' => $aid], $params, 'Params must match import id and area id.');
            }
        }

        // ---- Spot-check yielded tuples for the first area (safe access) ----
        $firstAreaKey = (string) $areaIds[0];
        /** @var list<Scope> $listForFirstArea */
        $listForFirstArea = $byArea[$firstAreaKey] ?? [];
        self::assertNotEmpty($listForFirstArea, "No scopes for first dispatch_area {$firstAreaKey}.");

        $tuples = array_map(
            /** @return array{0:string,1:string,2:string,3:string} */
            fn (Scope $s) => [$s->scopeType, $s->scopeId, $s->granularity, $s->periodKey],
            $listForFirstArea
        );

        $expectedForFirstArea = [
            ['dispatch_area', (string) $areaIds[0], Period::ALL, Period::ALL_ANCHOR_DATE],
            ['dispatch_area', (string) $areaIds[0], Period::YEAR, '2023-01-01'],
            ['dispatch_area', (string) $areaIds[0], Period::QUARTER, '2025-10-01'],
            // MONTH -> none
            ['dispatch_area', (string) $areaIds[0], Period::WEEK, '2025-10-27'],
            ['dispatch_area', (string) $areaIds[0], Period::DAY, '2025-11-01'],
            ['dispatch_area', (string) $areaIds[0], Period::DAY, '2025-11-02'],
        ];
        self::assertSame($expectedForFirstArea, $tuples, 'Yielded scopes (first dispatch_area) are incorrect or out of order.');
    }
}
