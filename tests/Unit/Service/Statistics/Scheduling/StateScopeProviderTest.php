<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Scheduling;

use App\Model\Scope;
use App\Service\Statistics\Scheduling\Sql\ProviderSql;
use App\Service\Statistics\Scheduling\StateScopeProvider;
use App\Service\Statistics\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class StateScopeProviderTest extends TestCase
{
    public function testProvideForImportYieldsAllExpectedScopesInOrder(): void
    {
        $importId = 7;

        // Step 1: distinct state IDs
        $stateIds = [10, 20];

        // Step 2: define what DB returns for each period call
        $yearKeys = ['2023-01-01'];
        $quarterKeys = ['2025-10-01'];
        $monthKeys = [];
        $weekKeys = ['2025-10-27'];
        $dayKeys = ['2025-11-01', '2025-11-02'];

        // We expect 1 call for state IDs + 5 granularities * 2 states = 11 calls
        $expectedTotalCalls = 1 + (5 * count($stateIds));

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
                // state IDs first
                $stateIds,
                // now granularities for state 10
                $yearKeys, $quarterKeys, $monthKeys, $weekKeys, $dayKeys,
                // then same for state 20
                $yearKeys, $quarterKeys, $monthKeys, $weekKeys, $dayKeys,
            );

        $provider = new StateScopeProvider($db);
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        // 1) Verify the number of yielded scopes.
        // For each of 2 states:
        // - 1 ALL
        // - 1 per key in yearKeys (1)
        // - 1 per key in quarterKeys (1)
        // - monthKeys (0)
        // - 1 per key in weekKeys (1)
        // - 2 per key in dayKeys (2)
        // => total per state = 1 + 1 + 1 + 0 + 1 + 2 = 6 scopes
        // => total = 12
        self::assertCount(12, $scopes, 'Unexpected number of yielded scopes.');

        // Basic shape checks
        /** @var list<Scope> $scopes */
        foreach ($scopes as $scope) {
            // Type is already narrowed to Scope by static analysis; assert meaningful fields instead.
            self::assertSame('state', $scope->scopeType);
            self::assertContains((int) $scope->scopeId, $stateIds, 'Unexpected state id.');
            self::assertContains($scope->granularity, Period::allGranularities(), 'Unexpected granularity.');
        }

        // The first yielded scope per state must be the ALL anchor
        /** @var array<string, list<Scope>> $byState */
        $byState = [];
        foreach ($scopes as $scope) {
            $byState[$scope->scopeId][] = $scope;
        }

        foreach ($stateIds as $sid) {
            $key = (string) $sid;

            // Safely get the list for this state (empty if missing)
            /** @var list<Scope> $list */
            $list = $byState[$key] ?? [];

            self::assertNotEmpty($list, "Empty scope list for state id {$key}.");

            // Get first element without indexing; reset() returns false on empty
            $first = reset($list);
            self::assertNotFalse($first, "Failed to get first scope for state id {$key}.");
            /* @var \App\Model\Scope $first */

            self::assertSame(Period::ALL, $first->granularity, 'First scope per state must be ALL.');
            self::assertSame(Period::ALL_ANCHOR_DATE, $first->periodKey, 'ALL anchor date mismatch.');
        }

        // 4) Verify captured SQL calls
        // First call should be for distinct state IDs
        self::assertStringContainsString(
            'SELECT DISTINCT state_id',
            $capturedSql[0],
            'First SQL call must query distinct state_id.'
        );
        self::assertStringContainsString(
            'WHERE import_id = :id AND state_id IS NOT NULL',
            $capturedSql[0],
            'First SQL must filter by import id and exclude NULLs.'
        );
        self::assertSame(['id' => $importId], $capturedParams[0]);

        // Following calls are for each state and each granularity (YEAR..DAY)
        $expectedExprs = [
            ProviderSql::periodKeySelect(Period::YEAR),
            ProviderSql::periodKeySelect(Period::QUARTER),
            ProviderSql::periodKeySelect(Period::MONTH),
            ProviderSql::periodKeySelect(Period::WEEK),
            ProviderSql::periodKeySelect(Period::DAY),
        ];

        $expectedParamSets = [];
        foreach ($stateIds as $sid) {
            foreach ($expectedExprs as $expr) {
                $expectedParamSets[] = ['id' => $importId, 'sid' => $sid];
            }
        }

        // Skip the first call (state_id query)
        $granularSqlCalls = array_slice($capturedSql, 1);
        $granularParamCalls = array_slice($capturedParams, 1);

        self::assertCount(count($expectedParamSets), $granularSqlCalls);

        foreach ($granularSqlCalls as $i => $sql) {
            $params = $granularParamCalls[$i];
            [$expectedId, $expectedSid] = [$params['id'], $params['sid']];

            self::assertStringContainsString('SELECT DISTINCT', $sql);
            self::assertStringContainsString('FROM allocation', $sql);
            self::assertStringContainsString('WHERE import_id = :id AND state_id = :sid', $sql);
            self::assertStringContainsString('ORDER BY k ASC', $sql);
            self::assertSame($importId, $expectedId);
            self::assertContains($expectedSid, $stateIds);
        }
    }
}
