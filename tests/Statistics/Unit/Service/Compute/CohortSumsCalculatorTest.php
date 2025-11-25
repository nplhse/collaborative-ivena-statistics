<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Compute;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Compute\CohortSumsCalculator;
use App\Statistics\Infrastructure\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CohortSumsCalculatorTest extends TestCase
{
    #[DataProvider('provideSupportsMatrix')]
    public function testSupportsMatrix(string $scopeType, bool $expected): void
    {
        $db = $this->createMock(Connection::class);
        $calc = new CohortSumsCalculator($db);

        $scope = new Scope($scopeType, 'val', Period::DAY, '2025-11-01');
        self::assertSame($expected, $calc->supports($scope));
    }

    /**
     * @return iterable<array{0:string,1:bool}>
     */
    public static function provideSupportsMatrix(): iterable
    {
        yield ['hospital_tier', true];
        yield ['hospital_size', true];
        yield ['hospital_location', true];
        yield ['hospital_cohort', true];

        yield ['public', false];
        yield ['hospital', false];
        yield ['dispatch_area', false];
        yield ['state', false];
        yield ['something_else', false];
    }

    /**
     * @param array<string,string> $expectedFilterParams
     */
    #[DataProvider('provideScopeFilterCases')]
    public function testCalculateBuildsExpectedSqlAndParams(
        string $scopeType,
        string $scopeId,
        string $expectedFilterSqlFragment,
        array $expectedFilterParams,
    ): void {
        $db = $this->createMock(Connection::class);

        $capturedSql = null;
        $capturedParams = null;

        $db->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::callback(function (string $sql) use (&$capturedSql, $expectedFilterSqlFragment): bool {
                    $capturedSql = $sql;

                    // Core structure
                    self::assertStringContainsString('INSERT INTO agg_allocations_cohort_sums', $sql, 'Missing INSERT target.');
                    self::assertStringContainsString('FROM agg_allocations_counts c', $sql, 'Must read from counts table.');
                    self::assertStringContainsString('JOIN hospital h ON h.id::text = c.scope_id', $sql, 'Must join hospital.');
                    self::assertStringContainsString("WHERE c.scope_type = 'hospital'", $sql, 'Must restrict to hospital rows.');
                    self::assertStringContainsString('AND c.period_gran = :gran', $sql, 'Missing period gran filter.');
                    self::assertStringContainsString('AND c.period_key  = :key', $sql, 'Missing period key filter.');
                    self::assertStringContainsString($expectedFilterSqlFragment, $sql, 'Missing hospital filter fragment.');
                    self::assertStringContainsString('ON CONFLICT (scope_type, scope_id, period_gran, period_key)', $sql, 'Missing conflict target.');
                    self::assertStringContainsString('DO UPDATE SET', $sql, 'Missing upsert update.');
                    self::assertStringContainsString('computed_at = now()', $sql, 'Missing computed_at update.');

                    return true;
                }),
                self::callback(function (array $params) use (&$capturedParams, $scopeType, $scopeId, $expectedFilterParams): bool {
                    $capturedParams = $params;

                    // Envelope params
                    self::assertSame($scopeType, $params['scope_type']);
                    self::assertSame($scopeId, $params['scope_id']);
                    self::assertSame(Period::QUARTER, $params['gran']); // we’ll pass QUARTER in the Scope below
                    self::assertSame('2025-10-01', $params['key']);

                    // Filter params
                    foreach ($expectedFilterParams as $k => $v) {
                        self::assertArrayHasKey($k, $params, "Missing expected filter param '$k'.");
                        self::assertSame($v, $params[$k], "Unexpected value for filter param '$k'.");
                    }

                    return true;
                })
            )
            ->willReturn(1);

        $calc = new CohortSumsCalculator($db);
        $scope = new Scope($scopeType, $scopeId, Period::QUARTER, '2025-10-01');
        $calc->calculate($scope);

        self::assertNotNull($capturedSql);
        self::assertNotNull($capturedParams);
    }

    /**
     * @return iterable<array{0:string,1:string,2:string,3:array<string,string>}>
     */
    public static function provideScopeFilterCases(): iterable
    {
        yield 'tier' => [
            'hospital_tier',
            'full', // scopeId
            'LOWER(h.tier) = :hv',
            ['hv' => 'full'],
        ];
        yield 'size' => [
            'hospital_size',
            'large',
            'LOWER(h.size) = :hv',
            ['hv' => 'large'],
        ];
        yield 'location' => [
            'hospital_location',
            'urban',
            'LOWER(h.location) = :hv',
            ['hv' => 'urban'],
        ];
        yield 'cohort basic_urban' => [
            'hospital_cohort',
            'basic_urban',
            'LOWER(h.tier) = :t AND LOWER(h.location) = :l',
            ['t' => 'basic', 'l' => 'urban'],
        ];
        yield 'cohort Extended_Rural (case-insensitive → lower)' => [
            'hospital_cohort',
            'Extended_Rural',
            'LOWER(h.tier) = :t AND LOWER(h.location) = :l',
            ['t' => 'extended', 'l' => 'rural'],
        ];
    }

    public function testCalculateThrowsOnInvalidCohortId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('executeStatement');

        $calc = new CohortSumsCalculator($db);
        $scope = new Scope('hospital_cohort', 'invalid', Period::DAY, '2025-11-01');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Invalid cohort id 'invalid'");
        $calc->calculate($scope);
    }

    public function testCalculateThrowsOnUnsupportedScopeType(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('executeStatement');

        $calc = new CohortSumsCalculator($db);
        $scope = new Scope('public', 'all', Period::ALL, Period::ALL_ANCHOR_DATE);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported scopeType public');
        $calc->calculate($scope);
    }
}
