<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Scheduling\Sql;

use App\Statistics\Infrastructure\Scheduling\Sql\ProviderSql;
use App\Statistics\Infrastructure\Util\Period;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderSqlTest extends TestCase
{
    #[DataProvider('provideGranularities')]
    public function testPeriodKeySelectReturnsExpectedSql(string $gran, string $expected): void
    {
        $result = ProviderSql::periodKeySelect($gran);
        self::assertSame(
            $expected,
            $result,
            "Unexpected SQL expression for granularity '$gran'."
        );
    }

    /**
     * @return iterable<array{0:string,1:string}>
     */
    public static function provideGranularities(): iterable
    {
        yield 'day' => [
            Period::DAY,
            'period_day(arrival_at)::date',
        ];
        yield 'week' => [
            Period::WEEK,
            'period_week(arrival_at)::date',
        ];
        yield 'month' => [
            Period::MONTH,
            'period_month(arrival_at)::date',
        ];
        yield 'quarter' => [
            Period::QUARTER,
            'period_quarter(arrival_at)::date',
        ];
        yield 'year' => [
            Period::YEAR,
            'period_year(arrival_at)::date',
        ];
        yield 'all' => [
            Period::ALL,
            "'".Period::ALL_ANCHOR_DATE."'::date",
        ];
    }

    public function testThrowsExceptionForUnknownGranularity(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown granularity');
        ProviderSql::periodKeySelect('invalid');
    }
}
