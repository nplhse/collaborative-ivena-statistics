<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Compute;

use App\Model\Scope;
use App\Service\Statistics\Compute\OverviewCountCalculator;
use App\Service\Statistics\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverviewCountCalculatorTest extends TestCase
{
    #[DataProvider('provideSupportsMatrix')]
    public function testSupportsMatrix(string $scopeType, bool $expected): void
    {
        $db = $this->createMock(Connection::class);
        $calc = new OverviewCountCalculator($db);

        $scope = new Scope($scopeType, 'id', Period::DAY, '2025-11-01');
        self::assertSame($expected, $calc->supports($scope));
    }

    /**
     * @return iterable<array{0:string,1:bool}>
     */
    public static function provideSupportsMatrix(): iterable
    {
        yield ['public', true];
        yield ['hospital', true];
        yield ['dispatch_area', true];
        yield ['state', true];

        yield ['hospital_tier', false];
        yield ['hospital_size', false];
        yield ['hospital_location', false];
        yield ['hospital_cohort', false];
        yield ['something_else', false];
    }

    public function testCalculateUsesDbResultAndUpsertsAllFields(): void
    {
        $scope = new Scope('state', '17', Period::DAY, '2025-11-01');

        $dbResultRow = [
            'total' => 100,
            'gender_m' => 40,
            'gender_w' => 50,
            'gender_d' => 10,
            'gender_u' => 0,
            'urg_1' => 10,
            'urg_2' => 20,
            'urg_3' => 70,
            'cathlab_required' => 5,
            'resus_required' => 3,
            'is_cpr' => 1,
            'is_ventilated' => 2,
            'is_shock' => 3,
            'is_pregnant' => 0,
            'with_physician' => 80,
            'infectious' => 5,
        ];

        $db = $this->createMock(Connection::class);

        $selectSqlCaptured = null;
        $selectParamsCaptured = null;
        $upsertSqlCaptured = null;
        $upsertParamsCaptured = null;

        // Expect SELECT (fetchAssociative)
        $db->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                self::callback(function (string $sql) use (&$selectSqlCaptured) {
                    $selectSqlCaptured = $sql;
                    self::assertStringContainsString('FROM allocation', $sql);
                    self::assertStringContainsString('WHERE state_id = :scope_id::int', $sql);
                    self::assertStringContainsString('AND period_day(arrival_at) = :period_key::date', $sql);

                    return true;
                }),
                self::callback(function (array $params) use (&$selectParamsCaptured) {
                    $selectParamsCaptured = $params;

                    // Allow int/string mismatch and flexible order
                    self::assertArrayHasKey('scope_id', $params, 'scope_id missing');
                    self::assertArrayHasKey('period_key', $params, 'period_key missing');

                    self::assertSame('17', (string) $params['scope_id'], 'scope_id mismatch (normalize to string)');
                    self::assertSame('2025-11-01', $params['period_key'], 'period_key mismatch');

                    // Optional: ensure no extras
                    self::assertCount(2, $params, 'Unexpected extra params');

                    return true;
                })
            )
            ->willReturn($dbResultRow);

        // Expect UPSERT (executeStatement)
        $db->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::callback(function (string $sql) use (&$upsertSqlCaptured) {
                    $upsertSqlCaptured = $sql;
                    self::assertStringContainsString('INSERT INTO agg_allocations_counts', $sql);
                    self::assertStringContainsString('ON CONFLICT (scope_type, scope_id, period_gran, period_key)', $sql);
                    self::assertStringContainsString('DO UPDATE SET', $sql);
                    // tolerate alignment/whitespace differences
                    self::assertMatchesRegularExpression('/computed_at\s*=\s*now\(\)\s*;?/', $sql);

                    return true;
                }),
                self::callback(function (array $params) use (&$upsertParamsCaptured, $dbResultRow, $scope) {
                    $upsertParamsCaptured = $params;

                    self::assertSame($scope->scopeType, $params['scope_type']);
                    self::assertSame($scope->scopeId, $params['scope_id']);
                    self::assertSame($scope->granularity, $params['period_gran']);
                    self::assertSame($scope->periodKey, $params['period_key']);

                    foreach ($dbResultRow as $key => $value) {
                        self::assertArrayHasKey($key, $params, "Missing param '$key' in upsert.");
                        self::assertSame($value, $params[$key], "Unexpected value for '$key'.");
                    }

                    return true;
                })
            )
            ->willReturn(1);

        $calc = new OverviewCountCalculator($db);
        $calc->calculate($scope);

        // Simple sanity checks on captured SQL
        self::assertNotNull($selectSqlCaptured);
        self::assertNotNull($upsertSqlCaptured);
    }

    public function testCalculateHandlesFalseRowAndUsesAnchorDateForAllGranularity(): void
    {
        // Use ALL granularity to verify period_key is replaced by ALL_ANCHOR_DATE
        $scope = new Scope('public', 'all', Period::ALL, 'ignored-key');

        $db = $this->createMock(Connection::class);

        // SELECT returns false => calculator must use zeroed row
        $db->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                self::callback(function (string $sql) {
                    // For public scope, ScopePeriodSql returns 'TRUE' for scope filter.
                    self::assertStringContainsString('WHERE TRUE', $sql);
                    // For ALL, period expr is also 'TRUE'
                    self::assertStringContainsString('AND TRUE', $sql);

                    return true;
                }),
                self::callback(function (array $params) {
                    // No params expected for public + ALL
                    self::assertSame([], $params);

                    return true;
                })
            )
            ->willReturn(false);

        $db->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::anything(),
                self::callback(function (array $params) {
                    // period_key must be the anchor date
                    self::assertSame(Period::ALL_ANCHOR_DATE, $params['period_key']);

                    // All counters should be zero
                    $expectedZeroFields = [
                        'total',
                        'gender_m', 'gender_w', 'gender_d', 'gender_u',
                        'urg_1', 'urg_2', 'urg_3',
                        'cathlab_required', 'resus_required',
                        'is_cpr', 'is_ventilated', 'is_shock', 'is_pregnant', 'with_physician',
                        'infectious',
                    ];
                    foreach ($expectedZeroFields as $f) {
                        self::assertArrayHasKey($f, $params, "Missing '$f' in UPSERT params.");
                        self::assertSame(0, $params[$f], "Expected zero for '$f'.");
                    }

                    return true;
                })
            )
            ->willReturn(1);

        $calc = new OverviewCountCalculator($db);
        $calc->calculate($scope);
    }
}
