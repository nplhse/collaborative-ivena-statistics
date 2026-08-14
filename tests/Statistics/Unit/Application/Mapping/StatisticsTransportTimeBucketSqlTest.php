<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Mapping;

use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatisticsTransportTimeBucketSqlTest extends TestCase
{
    #[DataProvider('boundaryMinutes')]
    public function testBucketKeyForMinutesUsesHalfOpenBoundaries(?int $minutes, string $expected): void
    {
        self::assertSame($expected, StatisticsTransportTimeBucketSql::bucketKeyForMinutes($minutes));
    }

    /**
     * @return iterable<string, array{0: ?int, 1: string}>
     */
    public static function boundaryMinutes(): iterable
    {
        yield 'null' => [null, 'unknown'];
        yield 'negative' => [-1, 'unknown'];
        yield 'zero' => [0, 'under_10'];
        yield 'nine' => [9, 'under_10'];
        yield 'ten' => [10, '10_20'];
        yield 'nineteen' => [19, '10_20'];
        yield 'twenty' => [20, '20_30'];
        yield 'fifty_nine' => [59, '50_60'];
        yield 'sixty' => [60, 'over_60'];
        yield 'ninety' => [90, 'over_60'];
    }

    public function testDisplayBucketsExcludeUnknown(): void
    {
        self::assertNotContains('unknown', StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS);
        self::assertSame(
            ['under_10', '10_20', '20_30', '30_40', '40_50', '50_60', 'over_60'],
            StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS,
        );
    }

    public function testTranslationKeyUsesDistributionDomain(): void
    {
        self::assertSame(
            'statistics.distribution.transport_time_bucket.under_10',
            StatisticsTransportTimeBucketSql::translationKey('under_10'),
        );
    }
}
