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
}
