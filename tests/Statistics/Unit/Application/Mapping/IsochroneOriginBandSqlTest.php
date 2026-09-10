<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Mapping;

use App\Statistics\Application\Mapping\IsochroneOriginBandSql;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsochroneOriginBandSqlTest extends TestCase
{
    #[DataProvider('minutesProvider')]
    public function testBandKeyFollowsTransportTimeBucketsUntilFifty(
        ?int $minutes,
        string $expectedKey,
    ): void {
        self::assertSame($expectedKey, IsochroneOriginBandSql::bandKeyForMinutes($minutes));
    }

    /**
     * @return iterable<string, array{0: ?int, 1: string}>
     */
    public static function minutesProvider(): iterable
    {
        yield 'null' => [null, IsochroneOriginBandSql::UNKNOWN];
        yield 'negative' => [-3, IsochroneOriginBandSql::UNKNOWN];
        yield 'zero' => [0, '10'];
        yield 'nine' => [9, '10'];
        yield 'ten' => [10, '20'];
        yield 'nineteen' => [19, '20'];
        yield 'twenty' => [20, '30'];
        yield 'forty_nine' => [49, '50'];
        yield 'fifty' => [50, IsochroneOriginBandSql::BEYOND_MAX];
        yield 'fifty_five' => [55, IsochroneOriginBandSql::BEYOND_MAX];
        yield 'sixty' => [60, IsochroneOriginBandSql::BEYOND_MAX];
    }

    public function testMappedBandsStayAlignedWithTransportTimeBuckets(): void
    {
        $map = [
            'unknown' => IsochroneOriginBandSql::UNKNOWN,
            'under_10' => '10',
            '10_20' => '20',
            '20_30' => '30',
            '30_40' => '40',
            '40_50' => '50',
            '50_60' => IsochroneOriginBandSql::BEYOND_MAX,
            'over_60' => IsochroneOriginBandSql::BEYOND_MAX,
        ];

        for ($minutes = -1; $minutes <= 90; ++$minutes) {
            $bucket = StatisticsTransportTimeBucketSql::bucketKeyForMinutes($minutes);
            self::assertSame(
                $map[$bucket],
                IsochroneOriginBandSql::bandKeyForMinutes($minutes),
                sprintf('Mismatch at %d minutes (transport bucket %s)', $minutes, $bucket),
            );
        }

        self::assertSame(
            $map[StatisticsTransportTimeBucketSql::bucketKeyForMinutes(null)],
            IsochroneOriginBandSql::bandKeyForMinutes(null),
        );
    }

    public function testMinutesForBandKeyRejectsUnknownKeys(): void
    {
        self::assertSame(20, IsochroneOriginBandSql::minutesForBandKey('20'));
        self::assertNull(IsochroneOriginBandSql::minutesForBandKey(IsochroneOriginBandSql::UNKNOWN));
        self::assertNull(IsochroneOriginBandSql::minutesForBandKey('15'));
    }
}
