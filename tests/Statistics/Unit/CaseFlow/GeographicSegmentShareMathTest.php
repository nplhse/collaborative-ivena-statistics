<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegmentShareMath;
use PHPUnit\Framework\TestCase;

final class GeographicSegmentShareMathTest extends TestCase
{
    public function testPercentReturnsNullForEmptyDenominator(): void
    {
        self::assertNull(GeographicSegmentShareMath::percent(0, 0));
        self::assertNull(GeographicSegmentShareMath::percent(5, 0));
        self::assertSame(0.0, GeographicSegmentShareMath::percent(0, 10));
        self::assertEqualsWithDelta(21.46, GeographicSegmentShareMath::percent(2146, 10_000), 0.0000001);
    }

    public function testDeltaPpUsesUnroundedShares(): void
    {
        self::assertNull(GeographicSegmentShareMath::deltaPp(null, 16.82));
        self::assertNull(GeographicSegmentShareMath::deltaPp(21.46, null));

        $segment = GeographicSegmentShareMath::percent(2146, 10_000);
        $reference = GeographicSegmentShareMath::percent(1682, 10_000);
        $delta = GeographicSegmentShareMath::deltaPp($segment, $reference);

        self::assertNotNull($segment);
        self::assertNotNull($reference);
        self::assertNotNull($delta);
        self::assertEqualsWithDelta(21.46, $segment, 0.0000001);
        self::assertEqualsWithDelta(16.82, $reference, 0.0000001);
        self::assertEqualsWithDelta(4.64, $delta, 0.0000001);
        self::assertSame(21.5, GeographicSegmentShareMath::roundDisplay($segment));
        self::assertSame(16.8, GeographicSegmentShareMath::roundDisplay($reference));
        self::assertSame(4.6, GeographicSegmentShareMath::roundDisplay($delta));
        self::assertSame(4.7, round(21.5 - 16.8, 1));
    }

    public function testRoundDisplayPreservesNullAndSign(): void
    {
        self::assertNull(GeographicSegmentShareMath::roundDisplay(null));
        self::assertSame(0.0, GeographicSegmentShareMath::roundDisplay(0.0));
        self::assertSame(-3.6, GeographicSegmentShareMath::roundDisplay(-3.64));
        self::assertSame(4.6, GeographicSegmentShareMath::roundDisplay(4.64));
    }
}
