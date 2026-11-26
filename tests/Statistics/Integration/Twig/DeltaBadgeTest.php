<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class DeltaBadgeTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testPositivePercentRendersBadgeWithSignAndAccessibleName(): void
    {
        $html = $this->render([
            'value' => 1.2,
            'unit' => 'percent',
            'colorizeTrend' => true,
            'ariaKey' => 'stats.top_lists.comparison.delta.aria',
            'data-testid' => 'delta',
        ]);

        self::assertStringContainsString('badge bg-green-lt stats-rank-shift-badge', $html);
        self::assertStringContainsString('+1,2%', $html);
        self::assertStringContainsString('aria-label="+1,2% versus comparison A"', $html);
        self::assertStringContainsString('data-testid="delta"', $html);
        self::assertStringNotContainsString('pp', $html);
    }

    public function testNegativeCountRendersTextWithoutBadge(): void
    {
        $html = $this->render([
            'value' => -3,
            'unit' => 'count',
            'display' => 'text',
            'colorizeTrend' => true,
            'ariaKey' => 'stats.top_lists.comparison.delta.aria',
        ]);

        self::assertStringContainsString('class="text-red"', $html);
        self::assertMatchesRegularExpression('/-3\s*</', $html);
        self::assertStringNotContainsString('badge', $html);
    }

    public function testNegativePercentBadgeKeepsExtraClassAndTitle(): void
    {
        $html = $this->render([
            'value' => -4.5,
            'unit' => 'percent',
            'colorizeTrend' => true,
            'ariaKey' => 'stats.reports.transport_time_profile.delta.aria',
            'extraClass' => 'stats-ttp-marker',
            'title' => 'Regular: 8,0 %',
        ]);

        self::assertStringContainsString('badge bg-red-lt stats-rank-shift-badge stats-ttp-marker', $html);
        self::assertStringContainsString('title="Regular: 8,0 %"', $html);
        self::assertStringContainsString('-4,5%', $html);
        self::assertStringContainsString('aria-label="-4,5% versus the overall population"', $html);
    }

    public function testZeroNeutralTextOmitsDirectionalColour(): void
    {
        $html = $this->render([
            'value' => 0,
            'unit' => 'percent',
            'display' => 'text',
            'whenZero' => 'neutral',
            'colorizeTrend' => true,
            'ariaKey' => 'stats.case_flow.segment.delta.aria',
        ]);

        self::assertStringContainsString('class="text-secondary"', $html);
        self::assertStringContainsString('0,0%', $html);
        self::assertStringNotContainsString('text-green', $html);
        self::assertStringNotContainsString('text-red', $html);
        self::assertStringNotContainsString('badge', $html);
    }

    /**
     * @return iterable<string, array{float, bool, bool, string}>
     */
    public static function trendColours(): iterable
    {
        yield 'increase, not coloured (default)' => [2.0, false, false, 'badge bg-secondary-lt'];
        yield 'decrease, not coloured (default)' => [-2.0, false, false, 'badge bg-secondary-lt'];
        yield 'increase, coloured' => [2.0, true, false, 'badge bg-green-lt'];
        yield 'decrease, coloured' => [-2.0, true, false, 'badge bg-red-lt'];
        yield 'increase, coloured and inverted' => [2.0, true, true, 'badge bg-red-lt'];
        yield 'decrease, coloured and inverted' => [-2.0, true, true, 'badge bg-green-lt'];
        // invertTrend alone has no effect: without colorizeTrend the change stays neutral.
        yield 'increase, inverted only' => [2.0, false, true, 'badge bg-secondary-lt'];
        yield 'decrease, inverted only' => [-2.0, false, true, 'badge bg-secondary-lt'];
    }

    #[DataProvider('trendColours')]
    public function testColourFollowsColorizeAndInvertTrend(float $value, bool $colorize, bool $invert, string $expectedClass): void
    {
        $html = $this->render([
            'value' => $value,
            'unit' => 'count',
            'colorizeTrend' => $colorize,
            'invertTrend' => $invert,
            'ariaKey' => 'stats.top_lists.comparison.delta.aria',
        ]);

        self::assertStringContainsString($expectedClass.' stats-rank-shift-badge', $html);
        // The sign carries the direction without colour.
        self::assertMatchesRegularExpression($value > 0 ? '/>\+2\s*</' : '/>-2\s*</', $html);
    }

    public function testMinutesUseMinuteSuffix(): void
    {
        $html = $this->render([
            'value' => 1.2,
            'unit' => 'minutes',
            'display' => 'text',
            'ariaKey' => 'stats.top_lists.comparison.delta.aria',
        ]);

        self::assertStringContainsString('+1,2 min', $html);
    }

    public function testDecimalUnitUsesOneDecimalPlaceWithoutSuffix(): void
    {
        $html = $this->render([
            'value' => 1.25,
            'unit' => 'decimal',
            'display' => 'text',
            'whenZero' => 'neutral',
            'ariaKey' => 'stats.closure.volume.deviation.absolute.aria',
        ]);

        self::assertStringContainsString('+1,3', $html);
        self::assertStringNotContainsString('%', $html);
    }

    public function testZeroIsHiddenByDefault(): void
    {
        $html = $this->render([
            'value' => 0,
            'unit' => 'percent',
            'ariaKey' => 'stats.top_lists.comparison.delta.aria',
        ]);

        self::assertSame('', trim($html));
    }

    public function testZeroNeutralOmitsSignAndDirectionalColour(): void
    {
        $html = $this->render([
            'value' => 0.0,
            'unit' => 'percent',
            'whenZero' => 'neutral',
            'colorizeTrend' => true,
            'invertTrend' => true,
            'ariaKey' => 'stats.case_flow.segment.delta.aria',
        ]);

        self::assertStringContainsString('bg-secondary-lt', $html);
        self::assertMatchesRegularExpression('/0,0%\s*</', $html);
        self::assertStringNotContainsString('+0,0%', $html);
        self::assertStringNotContainsString('bg-green-lt', $html);
        self::assertStringNotContainsString('bg-red-lt', $html);
    }

    public function testNullRendersNothing(): void
    {
        $html = $this->render([
            'value' => null,
            'unit' => 'percent',
            'whenZero' => 'neutral',
            'ariaKey' => 'stats.case_flow.segment.delta.aria',
        ]);

        self::assertSame('', trim($html));
    }

    public function testPercentagePointsUseSpelledOutUnitForTitleAndAccessibleName(): void
    {
        // Share 25,0 % vs. 20,0 %: +5,0 percentage points.
        $html = $this->render([
            'value' => 5.0,
            'unit' => 'pp',
            'ariaKey' => 'stats.top_lists.comparison.delta.aria',
        ]);

        self::assertMatchesRegularExpression('/>\+5,0 pp\s*</', $html);
        self::assertStringContainsString('title="percentage points (difference between two shares)"', $html);
        self::assertStringContainsString('aria-label="+5,0 percentage points (difference between two shares) versus comparison A"', $html);
        self::assertStringNotContainsString('%', $html);
    }

    public function testPercentagePointsKeepExplicitTitleAndUseGermanUnit(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        $translator->setLocale('de');

        $html = (string) $this->renderTwigComponent('Statistics:DeltaIndicator', [
            'value' => -2.5,
            'unit' => 'pp',
            'display' => 'text',
            'ariaKey' => 'stats.case_flow.segment.delta.aria',
            'title' => 'Regulär: 8,0 %',
        ]);

        self::assertMatchesRegularExpression('/>-2,5 %-Punkte\s*</', $html);
        self::assertStringContainsString('title="Regulär: 8,0 %"', $html);
        self::assertStringContainsString('aria-label="-2,5 Prozentpunkte (Differenz zweier Anteile) gegenüber der Grundgesamtheit"', $html);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        self::bootKernel();

        return (string) $this->renderTwigComponent('Statistics:DeltaIndicator', $props);
    }
}
