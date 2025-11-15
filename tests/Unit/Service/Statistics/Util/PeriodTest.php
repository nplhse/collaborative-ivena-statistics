<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Util;

use App\Service\Statistics\Util\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    public function testAllGranularitiesReturnsExpectedOrder(): void
    {
        $expected = [
            Period::ALL,
            Period::YEAR,
            Period::QUARTER,
            Period::MONTH,
            Period::WEEK,
            Period::DAY,
        ];

        self::assertSame(
            $expected,
            Period::allGranularities(),
            'allGranularities() should return the expected order.'
        );
    }

    public function testAllGranularitiesAreUnique(): void
    {
        $values = Period::allGranularities();

        // Prefer assertCount over assertSame(count(), count())
        self::assertCount(
            count(array_unique($values)),
            $values,
            'allGranularities() should not contain duplicate entries.'
        );
    }

    public function testAllGranularitiesCoverAllPublicPeriodConstants(): void
    {
        $refl = new \ReflectionClass(Period::class);
        $constants = $refl->getConstants();

        // Exclude non-granularity constants
        unset($constants['ALL_ANCHOR_DATE']);

        $periodConstantValues = array_values($constants);

        $methodValues = Period::allGranularities();
        sort($methodValues);
        sort($periodConstantValues);

        self::assertSame(
            $periodConstantValues,
            $methodValues,
            'allGranularities() must cover all period constants.'
        );
    }

    public function testAllAnchorDateIsValidIsoDateAndNotInFuture(): void
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', Period::ALL_ANCHOR_DATE);
        self::assertNotFalse($date, 'ALL_ANCHOR_DATE must be a valid ISO date (Y-m-d).');

        // Non-tautological sanity check: anchor date should not be in the future
        $today = new \DateTimeImmutable('today');
        self::assertLessThanOrEqual(
            $today->getTimestamp(),
            $date->getTimestamp(),
            'ALL_ANCHOR_DATE should not be in the future.'
        );
    }

    public function testNormalizePeriodKeyForAllReturnsAnchorDateRegardlessOfInput(): void
    {
        self::assertSame(
            Period::ALL_ANCHOR_DATE,
            Period::normalizePeriodKey(Period::ALL, '2025-11-08')
        );

        self::assertSame(
            Period::ALL_ANCHOR_DATE,
            Period::normalizePeriodKey('AlL', '1999-01-01')
        );
    }

    public function testNormalizePeriodKeyForYearNormalizesToFirstOfYear(): void
    {
        self::assertSame(
            '2025-01-01',
            Period::normalizePeriodKey(Period::YEAR, '2025-11-08'),
            'Full date should be normalized to first day of the year.'
        );

        self::assertSame(
            '2025-01-01',
            Period::normalizePeriodKey(Period::YEAR, '2025-05'),
            'Year-month should normalize to first day of the year.'
        );

        self::assertSame(
            '2025-01-01',
            Period::normalizePeriodKey(Period::YEAR, '2025'),
            'Year-only string should normalize to first day of that year.'
        );
    }

    public function testNormalizePeriodKeyForMonthNormalizesToFirstOfMonth(): void
    {
        self::assertSame(
            '2025-11-01',
            Period::normalizePeriodKey(Period::MONTH, '2025-11-08'),
            'Full date should be normalized to first day of that month.'
        );

        self::assertSame(
            '2025-11-01',
            Period::normalizePeriodKey(Period::MONTH, '2025-11'),
            'Year-month should normalize to first day of that month.'
        );

        self::assertSame(
            '2025-01-01',
            Period::normalizePeriodKey(Period::MONTH, '2025'),
            'Year-only string should normalize to January of that year.'
        );
    }

    public function testNormalizePeriodKeyDefaultBranchUsesFullDateResolution(): void
    {
        self::assertSame(
            '2025-11-08',
            Period::normalizePeriodKey(Period::WEEK, '2025-11-08'),
            'Exact Y-m-d should be preserved.'
        );

        self::assertSame(
            '2025-11-01',
            Period::normalizePeriodKey(Period::WEEK, '2025-11'),
            'Year-month should normalize to first of that month.'
        );

        self::assertSame(
            '2025-01-01',
            Period::normalizePeriodKey(Period::WEEK, '2025'),
            'Year-only should normalize to first of that year.'
        );
    }

    public function testNormalizePeriodKeyAcceptsNaturallyParseableDates(): void
    {
        $result = Period::normalizePeriodKey(Period::DAY, '10 Aug 2025');
        self::assertSame('2025-08-10', $result);
    }

    public function testNormalizePeriodKeyFallsBackToTodayOnUnparseableDate(): void
    {
        $today = new \DateTimeImmutable('today');
        $expected = $today->format('Y-m-d');

        $result = Period::normalizePeriodKey(Period::DAY, 'definitiv-kein-gueltiges-datum-!!!');

        self::assertSame(
            $expected,
            $result,
            'Unparseable dates should fall back to today.'
        );
    }

    public function testAnchorReturnsDateTimeImmutableForNormalizedKey(): void
    {
        $anchor = Period::anchor(Period::YEAR, '2025-11-08');

        self::assertSame(
            '2025-01-01',
            $anchor->format('Y-m-d'),
            'anchor() should return a DateTimeImmutable for the normalized key.'
        );
    }

    public function testAnchorForAllUsesAllAnchorDate(): void
    {
        $anchor = Period::anchor(Period::ALL, 'irgendwas');

        self::assertSame(
            Period::ALL_ANCHOR_DATE,
            $anchor->format('Y-m-d'),
            'anchor() for ALL should always use ALL_ANCHOR_DATE.'
        );
    }
}
