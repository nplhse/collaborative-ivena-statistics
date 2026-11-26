<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\KpiCard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KpiCardTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float|null, bool, bool, int, ?string, string, ?string}>
     */
    public static function trendProvider(): iterable
    {
        yield 'increase, not coloured' => [12, false, false, 0, KpiCard::TREND_UP, 'text-secondary', 'tabler:trending-up'];
        yield 'increase, coloured' => [12, true, false, 0, KpiCard::TREND_UP, 'text-green', 'tabler:trending-up'];
        yield 'increase, coloured and inverted' => [12, true, true, 0, KpiCard::TREND_UP, 'text-red', 'tabler:trending-up'];
        yield 'decrease, coloured' => [-3, true, false, 0, KpiCard::TREND_DOWN, 'text-red', 'tabler:trending-down'];
        yield 'decrease, coloured and inverted' => [-3, true, true, 0, KpiCard::TREND_DOWN, 'text-green', 'tabler:trending-down'];
        yield 'decrease, not coloured but inverted' => [-3, false, true, 0, KpiCard::TREND_DOWN, 'text-secondary', 'tabler:trending-down'];
        yield 'zero stays neutral' => [0, true, true, 0, KpiCard::TREND_FLAT, 'text-secondary', 'tabler:minus'];
        yield 'missing delta' => [null, true, false, 0, null, 'text-secondary', null];
        yield '0.04 rounds to 0.0' => [0.04, true, false, 1, KpiCard::TREND_FLAT, 'text-secondary', 'tabler:minus'];
        yield '-0.04 rounds to 0.0' => [-0.04, true, false, 1, KpiCard::TREND_FLAT, 'text-secondary', 'tabler:minus'];
        yield '0.05 rounds to 0.1' => [0.05, true, false, 1, KpiCard::TREND_UP, 'text-green', 'tabler:trending-up'];
        yield '0.4 with no fraction digits rounds to 0' => [0.4, true, false, 0, KpiCard::TREND_FLAT, 'text-secondary', 'tabler:minus'];
    }

    #[DataProvider('trendProvider')]
    public function testTrendFollowsRoundedDeltaAndCallerColouring(
        int|float|null $delta,
        bool $colorizeTrend,
        bool $invertTrend,
        int $fractionDigits,
        ?string $expectedDirection,
        string $expectedClass,
        ?string $expectedIcon,
    ): void {
        $card = new KpiCard();
        $card->delta = $delta;
        $card->colorizeTrend = $colorizeTrend;
        $card->invertTrend = $invertTrend;
        $card->fractionDigits = $fractionDigits;

        self::assertSame($expectedDirection, $card->getTrendDirection());
        self::assertSame($expectedClass, $card->getTrendClass());
        self::assertSame($expectedIcon, $card->getTrendIcon());
    }

    public function testSignIsOnlyAddedForIncreases(): void
    {
        $card = new KpiCard();

        $card->delta = 12;
        self::assertSame('+', $card->getDeltaSign());

        $card->delta = -3;
        self::assertSame('', $card->getDeltaSign());

        $card->delta = 0;
        self::assertSame('', $card->getDeltaSign());
    }

    public function testNegativeDeltaThatRoundsToZeroHasNoNegativeZero(): void
    {
        $card = new KpiCard();
        $card->delta = -0.04;
        $card->fractionDigits = 1;

        $rounded = $card->getRoundedDelta();

        self::assertSame(0.0, $rounded);
        self::assertSame('0', (string) $rounded);
        self::assertSame('', $card->getDeltaSign());
    }

    public function testDeltaUnitFallsBackToTheValueUnit(): void
    {
        $card = new KpiCard();
        self::assertNull($card->getDeltaUnitLabel());

        $card->unit = 'min';
        self::assertSame('min', $card->getDeltaUnitLabel());

        $card->unit = '%';
        $card->deltaUnit = 'pp';
        self::assertSame('pp', $card->getDeltaUnitLabel());
    }

    public function testOnlyNumbersCountAsNumericValues(): void
    {
        $card = new KpiCard();

        $card->value = 1240;
        self::assertTrue($card->isNumericValue());

        $card->value = 12.5;
        self::assertTrue($card->isNumericValue());

        $card->value = '3 h 20 min';
        self::assertFalse($card->isNumericValue());

        $card->value = null;
        self::assertFalse($card->isNumericValue());
    }

    public function testSparklinePointsFollowTablersLineGeometry(): void
    {
        $card = new KpiCard();
        $card->sparkline = array_map(
            static fn (string $label, int $value): array => ['label' => $label, 'value' => $value],
            ['Jun', 'Jul', 'Aug', 'Sep', 'Oct'],
            [40, 15, 0, 25, 126],
        );

        $points = $card->getSparklinePoints();

        // x spread evenly; y = 2 + (1 - (value - 0) / 126) × 20 of 24 units, e.g. 126 → 2 / 24 = 8.33 %, 0 → 22 / 24 = 91.67 %.
        self::assertSame([0.0, 25.0, 50.0, 75.0, 100.0], array_column($points, 'left'));
        self::assertSame([65.21, 81.75, 91.67, 75.13, 8.33], array_column($points, 'top'));
        self::assertSame(['Jun', 40], [$points[0]['label'], $points[0]['value']]);
        self::assertSame('40,15,0,25,126', $card->getSparklineValues());
    }

    public function testSparklineWithEqualValuesLiesAtTheBottom(): void
    {
        $card = new KpiCard();
        $card->sparkline = [['label' => 'Sep', 'value' => 5], ['label' => 'Oct', 'value' => 5]];

        self::assertSame([91.67, 91.67], array_column($card->getSparklinePoints(), 'top'));
    }

    public function testFewerThanTwoPointsHaveNoSparkline(): void
    {
        $card = new KpiCard();
        $card->sparkline = [['label' => 'Oct', 'value' => 5]];

        self::assertFalse($card->hasSparkline());
        self::assertSame([], $card->getSparklinePoints());
    }
}
