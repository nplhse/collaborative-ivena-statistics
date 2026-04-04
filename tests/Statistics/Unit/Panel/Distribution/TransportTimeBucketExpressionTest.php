<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\TransportTimeBucketExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportTimeBucketExpressionTest extends TestCase
{
    public function testOrderedBucketCodesIncludesUnknownLastInSequence(): void
    {
        self::assertSame([0, 1, 2, 3, 4, 5, 6, 8], TransportTimeBucketExpression::orderedBucketCodes());
    }

    public function testSqlContainsExpectedThresholds(): void
    {
        $sql = TransportTimeBucketExpression::sql('transport_time_minutes');
        self::assertStringContainsString('< 10', $sql);
        self::assertStringContainsString('< 20', $sql);
        self::assertStringContainsString('< 60', $sql);
        self::assertStringContainsString((string) TransportTimeBucketExpression::UNKNOWN, $sql);
    }

    /**
     * @return \Generator<string, array{0: int|null, 1: int}>
     */
    public static function minutesToBucketProvider(): \Generator
    {
        yield 'null' => [null, TransportTimeBucketExpression::UNKNOWN];
        yield 'negative' => [-1, TransportTimeBucketExpression::OVER_60];
        yield '0' => [0, TransportTimeBucketExpression::UNDER_10];
        yield '9' => [9, TransportTimeBucketExpression::UNDER_10];
        yield '10' => [10, TransportTimeBucketExpression::MIN_10_TO_20];
        yield '19' => [19, TransportTimeBucketExpression::MIN_10_TO_20];
        yield '20' => [20, TransportTimeBucketExpression::MIN_20_TO_30];
        yield '59' => [59, TransportTimeBucketExpression::MIN_50_TO_60];
        yield '60' => [60, TransportTimeBucketExpression::OVER_60];
        yield 'large' => [5000, TransportTimeBucketExpression::OVER_60];
    }

    #[DataProvider('minutesToBucketProvider')]
    public function testClassifyMinutesMatchesBucketRules(?int $minutes, int $expected): void
    {
        self::assertSame($expected, TransportTimeBucketExpression::classifyMinutes($minutes));
    }

    public function testSqlUsesSameConstantsAsClassifyMinutes(): void
    {
        $sql = TransportTimeBucketExpression::sql('m');
        foreach ([
            'NULL' => TransportTimeBucketExpression::UNKNOWN,
            '< 0' => TransportTimeBucketExpression::OVER_60,
            '< 10' => TransportTimeBucketExpression::UNDER_10,
            '< 20' => TransportTimeBucketExpression::MIN_10_TO_20,
            '< 30' => TransportTimeBucketExpression::MIN_20_TO_30,
            '< 40' => TransportTimeBucketExpression::MIN_30_TO_40,
            '< 50' => TransportTimeBucketExpression::MIN_40_TO_50,
            '< 60' => TransportTimeBucketExpression::MIN_50_TO_60,
        ] as $fragment => $code) {
            self::assertStringContainsString('THEN '.$code, $sql, 'Missing branch for '.$fragment);
        }
        self::assertStringContainsString('ELSE '.TransportTimeBucketExpression::OVER_60, $sql);
    }
}
