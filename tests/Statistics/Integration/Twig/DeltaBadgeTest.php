<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class DeltaBadgeTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testPositivePercentRendersBadgeWithSignAndAccessibleName(): void
    {
        $html = $this->render([
            'value' => 1.2,
            'unit' => 'percent',
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
            'ariaKey' => 'stats.case_flow.segment.delta.aria',
        ]);

        self::assertStringContainsString('class="text-secondary"', $html);
        self::assertStringContainsString('0,0%', $html);
        self::assertStringNotContainsString('text-green', $html);
        self::assertStringNotContainsString('text-red', $html);
        self::assertStringNotContainsString('badge', $html);
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

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        self::bootKernel();

        return (string) $this->renderTwigComponent('Statistics:DeltaIndicator', $props);
    }
}
