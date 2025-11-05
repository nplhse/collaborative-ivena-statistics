<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Compute\Sql;

use App\Model\Scope;
use App\Service\Statistics\Compute\Sql\ScopePeriodSql;
use App\Service\Statistics\Util\Period;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopePeriodSqlTest extends TestCase
{
    private DummyScopePeriodSql $sut;

    protected function setUp(): void
    {
        $this->sut = new DummyScopePeriodSql();
    }

    /**
     * @param array<string, string|int|float|bool|null> $expectedParams
     */
    #[DataProvider('provideScopeTypes')]
    public function testBuildScopeWhereReturnsExpectedSql(string $scopeType, string $expectedSql, array $expectedParams): void
    {
        $scope = new Scope($scopeType, '42', Period::DAY, '2024-11-01');
        $result = $this->sut->callBuildScopeWhere($scope);

        self::assertSame($expectedSql, $result['sql'], 'Unexpected SQL for given scope type.');
        self::assertSame($expectedParams, $result['params'], 'Unexpected params for given scope type.');
    }

    /**
     * @return iterable<array{0:string,1:string,2:array<string, string|int|float|bool|null>}>
     */
    public static function provideScopeTypes(): iterable
    {
        yield 'public-like scopes' => [
            'public',
            'TRUE',
            [],
        ];
        yield 'hospital' => [
            'hospital',
            'hospital_id = :scope_id::int',
            ['scope_id' => '42'],
        ];
        yield 'dispatch_area' => [
            'dispatch_area',
            'dispatch_area_id = :scope_id::int',
            ['scope_id' => '42'],
        ];
        yield 'state' => [
            'state',
            'state_id = :scope_id::int',
            ['scope_id' => '42'],
        ];
    }

    public function testBuildScopeWhereThrowsOnUnknownScopeType(): void
    {
        $scope = new Scope('invalid', '1', Period::DAY, '2024-11-01');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown scopeType');
        $this->sut->callBuildScopeWhere($scope);
    }

    /**
     * @param array<string, string|int|float|bool|null> $expectedParams
     */
    #[DataProvider('provideGranularities')]
    public function testBuildPeriodExprReturnsExpectedSql(string $granularity, string $expectedSql, array $expectedParams): void
    {
        $scope = new Scope('hospital', '1', $granularity, '2024-11-01');
        $result = $this->sut->callBuildPeriodExpr($scope);

        self::assertSame($expectedSql, $result['sql'], 'Unexpected SQL for given granularity.');
        self::assertSame($expectedParams, $result['params'], 'Unexpected params for given granularity.');
    }

    /**
     * @return iterable<array{0:string,1:string,2:array<string, string|int|float|bool|null>}>
     */
    public static function provideGranularities(): iterable
    {
        yield 'all' => [
            Period::ALL,
            'TRUE',
            [],
        ];
        yield 'day' => [
            Period::DAY,
            'period_day(arrival_at) = :period_key::date',
            ['period_key' => '2024-11-01'],
        ];
        yield 'week' => [
            Period::WEEK,
            'period_week(arrival_at) = :period_key::date',
            ['period_key' => '2024-11-01'],
        ];
        yield 'month' => [
            Period::MONTH,
            'period_month(arrival_at) = :period_key::date',
            ['period_key' => '2024-11-01'],
        ];
        yield 'quarter' => [
            Period::QUARTER,
            'period_quarter(arrival_at) = :period_key::date',
            ['period_key' => '2024-11-01'],
        ];
        yield 'year' => [
            Period::YEAR,
            'period_year(arrival_at) = :period_key::date',
            ['period_key' => '2024-11-01'],
        ];
    }

    public function testBuildPeriodExprThrowsOnUnknownGranularity(): void
    {
        $scope = new Scope('hospital', '1', 'invalid', '2024-11-01');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown granularity');
        $this->sut->callBuildPeriodExpr($scope);
    }
}

/**
 * Dummy wrapper class to expose private trait methods for testing.
 */
final class DummyScopePeriodSql
{
    use ScopePeriodSql {
        buildScopeWhere as public callBuildScopeWhere;
        buildPeriodExpr as public callBuildPeriodExpr;
    }
}
