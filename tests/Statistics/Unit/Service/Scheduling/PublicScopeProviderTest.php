<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Scheduling;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Scheduling\PublicScopeProvider;
use App\Statistics\Infrastructure\Scheduling\Sql\ProviderSql;
use App\Statistics\Infrastructure\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class PublicScopeProviderTest extends TestCase
{
    public function testProvideForImportYieldsAllScopesInOrderAndQueriesDbCorrectly(): void
    {
        $importId = 99;

        // Prepare distinct keys returned by DB per granularity (in query order).
        $yearKeys = ['2022-01-01', '2023-01-01'];
        $quarterKeys = ['2025-10-01'];
        $monthKeys = []; // empty should yield no scopes for MONTH
        $weekKeys = ['2025-10-27', '2025-11-03'];
        $dayKeys = ['2025-11-01'];

        $capturedSql = [];
        $capturedParam = [];

        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(5))
            ->method('fetchFirstColumn')
            ->with(
                self::callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql[] = $sql;

                    return true; // further validation below
                }),
                self::callback(function (array $params) use (&$capturedParam, $importId): bool {
                    $capturedParam[] = $params;

                    // Ensure params are passed through correctly
                    return $params === ['id' => $importId];
                })
            )
            ->willReturnOnConsecutiveCalls(
                $yearKeys, $quarterKeys, $monthKeys, $weekKeys, $dayKeys
            );

        $provider = new PublicScopeProvider($db);

        // Collect all yielded scopes
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        // 1) First scope must be the ALL anchor scope.
        self::assertNotEmpty($scopes, 'No scopes yielded.');
        self::assertSame('public', $scopes[0]->scopeType);
        self::assertSame('all', $scopes[0]->scopeId);
        self::assertSame(Period::ALL, $scopes[0]->granularity);
        self::assertSame(Period::ALL_ANCHOR_DATE, $scopes[0]->periodKey);

        // 2) Check the full, expected ordered list of (type,id,gran,key)
        $expectedTuples = [
            // ALL first
            ['public', 'all', Period::ALL, Period::ALL_ANCHOR_DATE],
            // YEAR (2 keys)
            ['public', 'all', Period::YEAR, '2022-01-01'],
            ['public', 'all', Period::YEAR, '2023-01-01'],
            // QUARTER (1 key)
            ['public', 'all', Period::QUARTER, '2025-10-01'],
            // MONTH (no keys)
            // WEEK (2 keys)
            ['public', 'all', Period::WEEK, '2025-10-27'],
            ['public', 'all', Period::WEEK, '2025-11-03'],
            // DAY (1 key)
            ['public', 'all', Period::DAY, '2025-11-01'],
        ];

        $actualTuples = array_map(
            fn (Scope $s) => [$s->scopeType, $s->scopeId, $s->granularity, $s->periodKey],
            $scopes
        );

        self::assertSame($expectedTuples, $actualTuples, 'Yielded scopes are incorrect or out of order.');

        // 3) Validate SQL fragments contain the correct period expressions, in call order.
        // Calls happen for YEAR, QUARTER, MONTH, WEEK, DAY (ALL is not queried).
        $expectedExprs = [
            ProviderSql::periodKeySelect(Period::YEAR),
            ProviderSql::periodKeySelect(Period::QUARTER),
            ProviderSql::periodKeySelect(Period::MONTH),
            ProviderSql::periodKeySelect(Period::WEEK),
            ProviderSql::periodKeySelect(Period::DAY),
        ];

        self::assertCount(5, $capturedSql, 'Unexpected number of SQL calls captured.');
        foreach ($capturedSql as $i => $sql) {
            self::assertStringContainsString(
                'SELECT DISTINCT '.$expectedExprs[$i].' AS k',
                $sql,
                "SQL for call #$i does not contain the expected period expression."
            );
            self::assertStringContainsString('FROM allocation', $sql, 'SQL must select from allocation.');
            self::assertStringContainsString('WHERE import_id = :id', $sql, 'SQL must filter by import id.');
            self::assertStringContainsString('ORDER BY k ASC', $sql, 'SQL must order by key ascending.');
        }

        // 4) Validate params for each call.
        foreach ($capturedParam as $params) {
            self::assertSame(['id' => $importId], $params, 'Params must pass the import id unchanged.');
        }
    }
}
