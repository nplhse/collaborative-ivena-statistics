<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Validation;

use App\Encounter\Application\Validation\ShareEstimate;
use PHPUnit\Framework\TestCase;

/**
 * Wilson score interval at 95 % (z = 1.96), checked by hand:
 * center = (p + z²/2n) / (1 + z²/n), half = z·√(p(1−p)/n + z²/4n²) / (1 + z²/n).
 */
final class ShareEstimateTest extends TestCase
{
    public function testThreeOfFour(): void
    {
        // p = 0.75, z²/n = 0.9604: center = 1.2302 / 1.9604 = 0.6275, half = 1.96 · 0.3270 / 1.9604 = 0.3269.
        $estimate = new ShareEstimate(3, 4);

        self::assertSame(0.75, $estimate->share);
        self::assertEqualsWithDelta(0.3006, $estimate->lower, 0.0005);
        self::assertEqualsWithDelta(0.9544, $estimate->upper, 0.0005);
    }

    public function testAllCorrectStillHasALowerBound(): void
    {
        // p = 1: center = 1.4802 / 1.9604 = 0.7550, half = 1.96 · 0.2450 / 1.9604 = 0.2450.
        $estimate = new ShareEstimate(4, 4);

        self::assertSame(1.0, $estimate->share);
        self::assertEqualsWithDelta(0.5101, $estimate->lower, 0.0005);
        self::assertSame(1.0, $estimate->upper);
    }

    public function testNoDecisiveVerdictGivesNoEstimate(): void
    {
        self::assertNull(ShareEstimate::of(0, 0));
    }
}
