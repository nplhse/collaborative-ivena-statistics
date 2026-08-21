<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\SummarizedReport;

use App\Statistics\Application\SummarizedReport\TransportTimeProfile\TransportTimeProfileMath;
use PHPUnit\Framework\TestCase;

final class TransportTimeProfileMathTest extends TestCase
{
    public function testPercentUsesBucketDenominator(): void
    {
        self::assertSame(40.0, TransportTimeProfileMath::percent(4, 10));
        self::assertSame(0.0, TransportTimeProfileMath::percent(4, 0));
        self::assertSame(24.1, TransportTimeProfileMath::percent(241, 1000));
    }

    public function testDeltaPpIsPercentagePointsNotRelativeChange(): void
    {
        self::assertSame(16.3, TransportTimeProfileMath::deltaPp(27.8, 11.5));
        self::assertSame(-3.3, TransportTimeProfileMath::deltaPp(8.2, 11.5));
    }

    public function testSmallSampleThreshold(): void
    {
        self::assertTrue(TransportTimeProfileMath::isSmallSample(9));
        self::assertFalse(TransportTimeProfileMath::isSmallSample(10));
        self::assertFalse(TransportTimeProfileMath::isSmallSample(0));
    }

    public function testHeatClassLevelsAndSmallSampleDamping(): void
    {
        self::assertSame('', TransportTimeProfileMath::heatClass(1.0, false));
        self::assertSame('stats-ttp-heat-high-1', TransportTimeProfileMath::heatClass(4.0, false));
        self::assertSame('stats-ttp-heat-high-2', TransportTimeProfileMath::heatClass(8.0, false));
        self::assertSame('stats-ttp-heat-high-3', TransportTimeProfileMath::heatClass(16.3, false));
        self::assertSame('stats-ttp-heat-low-1', TransportTimeProfileMath::heatClass(-4.0, false));
        self::assertSame('', TransportTimeProfileMath::heatClass(4.0, true));
        self::assertSame('stats-ttp-heat-high-2', TransportTimeProfileMath::heatClass(20.0, true));
    }

    public function testHeatClassUsesHighLowDirection(): void
    {
        self::assertSame('', TransportTimeProfileMath::heatClass(0.0, false));
        self::assertStringContainsString('high', TransportTimeProfileMath::heatClass(16.3, false));
        self::assertStringContainsString('low', TransportTimeProfileMath::heatClass(-16.3, false));
    }

    public function testDeltaBadgeClassMapsHeatToSuccessAndDanger(): void
    {
        self::assertSame('bg-green-lt', TransportTimeProfileMath::deltaBadgeClass('stats-ttp-heat-high-2'));
        self::assertSame('bg-red-lt', TransportTimeProfileMath::deltaBadgeClass('stats-ttp-heat-low-1'));
        self::assertSame('', TransportTimeProfileMath::deltaBadgeClass(''));
    }

    public function testRankBadgeClassUsesSuccessDangerAndNeutralBlue(): void
    {
        self::assertSame('', TransportTimeProfileMath::rankBadgeClass(null, false));
        self::assertSame('', TransportTimeProfileMath::rankBadgeClass(0, false));
        self::assertSame('bg-green-lt', TransportTimeProfileMath::rankBadgeClass(1, false));
        self::assertSame('bg-red-lt', TransportTimeProfileMath::rankBadgeClass(-1, false));
        self::assertSame('bg-blue-lt', TransportTimeProfileMath::rankBadgeClass(null, true));
        self::assertSame('bg-blue-lt', TransportTimeProfileMath::rankBadgeClass(3, true));
    }

    public function testRankShiftClassUsesInsightThreshold(): void
    {
        self::assertSame('', TransportTimeProfileMath::rankShiftClass(null, false));
        self::assertSame('', TransportTimeProfileMath::rankShiftClass(0, false));
        self::assertSame('', TransportTimeProfileMath::rankShiftClass(1, false));
        self::assertSame('', TransportTimeProfileMath::rankShiftClass(-1, false));
        self::assertSame('stats-ttp-rank-shift-up', TransportTimeProfileMath::rankShiftClass(2, false));
        self::assertSame('stats-ttp-rank-shift-down', TransportTimeProfileMath::rankShiftClass(-2, false));
        self::assertSame('stats-ttp-rank-shift-new', TransportTimeProfileMath::rankShiftClass(null, true));
        self::assertSame('stats-ttp-rank-shift-new', TransportTimeProfileMath::rankShiftClass(3, true));
    }
}
