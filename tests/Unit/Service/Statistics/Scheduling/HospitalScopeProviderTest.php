<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Scheduling;

use App\Model\Scope;
use App\Service\Statistics\Scheduling\HospitalScopeProvider;
use App\Service\Statistics\Scheduling\Sql\ProviderSql;
use App\Service\Statistics\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class HospitalScopeProviderTest extends TestCase
{
    public function testProvideForImportYieldsAllExpectedScopesInOrder(): void
    {
        $importId = 5;

        // 1) Distinct hospital IDs returned by DB
        $hospitalIds = [3001, 3002];

        // 2) DB returns for each granularity call (per hospital)
        $yearKeys = ['2023-01-01']; // 1 key
        $quarterKeys = ['2025-10-01']; // 1 key
        $monthKeys = []; // 0 keys
        $weekKeys = ['2025-10-27']; // 1 key
        $dayKeys = ['2025-11-01', '2025-11-02']; // 2 keys

        // Expected DB calls: 1 for hospital IDs + (5 granularities * 2 hospitals) = 11
        $expectedTotalCalls = 1 + (5 * count($hospitalIds));

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
                // distinct hospital ids
                $hospitalIds,
                // granularities for hospital 3001
                $yearKeys, $quarterKeys, $monthKeys, $weekKeys, $dayKeys,
                // granularities for hospital 3002
                $yearKeys, $quarterKeys, $monthKeys, $weekKeys, $dayKeys,
            );

        $provider = new HospitalScopeProvider($db);
        /** @var list<Scope> $scopes */
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        // Per hospital: 1 (ALL) + 1 + 1 + 0 + 1 + 2 = 6 scopes -> total 12
        self::assertCount(12, $scopes, 'Unexpected number of yielded scopes.');

        // ---- Basic semantic checks (no tautological instanceOf) ----
        foreach ($scopes as $scope) {
            self::assertSame('hospital', $scope->scopeType);
            self::assertContains((int) $scope->scopeId, $hospitalIds, 'Unexpected hospital id.');
            self::assertContains($scope->granularity, Period::allGranularities(), 'Unexpected granularity.');
        }

        // ---- Group by hospital id (safe access) ----
        /** @var array<string, list<Scope>> $byHospital */
        $byHospital = [];
        foreach ($scopes as $scope) {
            $byHospital[$scope->scopeId][] = $scope;
        }

        // First yielded scope per hospital must be the ALL anchor
        foreach ($hospitalIds as $hid) {
            $key = (string) $hid;

            /** @var list<Scope> $list */
            $list = $byHospital[$key] ?? [];
            self::assertNotEmpty($list, "Empty scope list for hospital id {$key}.");

            $first = reset($list);
            self::assertNotFalse($first, "Failed to get first scope for hospital id {$key}.");
            /* @var Scope $first */

            self::assertSame(Period::ALL, $first->granularity, 'First scope per hospital must be ALL.');
            self::assertSame(Period::ALL_ANCHOR_DATE, $first->periodKey, 'ALL anchor date mismatch.');
        }

        // ---- Validate the first SQL call retrieves distinct hospital_id ----
        self::assertStringContainsString(
            'SELECT DISTINCT hospital_id',
            $capturedSql[0],
            'First SQL must query distinct hospital_id.'
        );
        self::assertStringContainsString(
            'WHERE import_id = :id AND hospital_id IS NOT NULL',
            $capturedSql[0],
            'First SQL must filter by import id and exclude NULLs.'
        );
        self::assertSame(['id' => $importId], $capturedParams[0], 'First call params must pass import id.');

        // ---- Following calls: for each hospital, YEAR..DAY in order ----
        $expectedExprs = [
            ProviderSql::periodKeySelect(Period::YEAR),
            ProviderSql::periodKeySelect(Period::QUARTER),
            ProviderSql::periodKeySelect(Period::MONTH),
            ProviderSql::periodKeySelect(Period::WEEK),
            ProviderSql::periodKeySelect(Period::DAY),
        ];

        $granularSqlCalls = array_slice($capturedSql, 1);
        $granularParamCalls = array_slice($capturedParams, 1);

        self::assertCount(5 * count($hospitalIds), $granularSqlCalls, 'Unexpected number of granular SQL calls.');

        foreach ($hospitalIds as $idx => $hid) {
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
                self::assertStringContainsString('WHERE import_id = :id AND hospital_id = :hid', $sql);
                self::assertStringContainsString('ORDER BY k ASC', $sql);

                self::assertSame(['id' => $importId, 'hid' => $hid], $params, 'Params must match import id and hospital id.');
            }
        }

        // ---- Spot-check yielded tuples for the first hospital (safe access) ----
        $firstHospitalKey = (string) $hospitalIds[0];
        /** @var list<Scope> $listForFirstHospital */
        $listForFirstHospital = $byHospital[$firstHospitalKey] ?? [];
        self::assertNotEmpty($listForFirstHospital, "No scopes for first hospital {$firstHospitalKey}.");

        $tuples = array_map(
            /** @return array{0:string,1:string,2:string,3:string} */
            fn (Scope $s) => [$s->scopeType, $s->scopeId, $s->granularity, $s->periodKey],
            $listForFirstHospital
        );

        $expectedForFirstHospital = [
            ['hospital', (string) $hospitalIds[0], Period::ALL, Period::ALL_ANCHOR_DATE],
            ['hospital', (string) $hospitalIds[0], Period::YEAR, '2023-01-01'],
            ['hospital', (string) $hospitalIds[0], Period::QUARTER, '2025-10-01'],
            // MONTH -> none
            ['hospital', (string) $hospitalIds[0], Period::WEEK, '2025-10-27'],
            ['hospital', (string) $hospitalIds[0], Period::DAY, '2025-11-01'],
            ['hospital', (string) $hospitalIds[0], Period::DAY, '2025-11-02'],
        ];
        self::assertSame($expectedForFirstHospital, $tuples, 'Yielded scopes (first hospital) are incorrect or out of order.');
    }
}
