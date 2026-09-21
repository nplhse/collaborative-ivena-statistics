<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class BadgeComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersLabelAndDefaultVariant(): void
    {
        $html = (string) $this->renderTwigComponent('Badge', [
            'label' => 'Pending',
        ]);

        self::assertStringContainsString('badge bg-secondary-lt text-secondary-lt-fg', $html);
        self::assertStringContainsString('Pending', $html);
    }

    public function testRendersContentSlotAndTestId(): void
    {
        $rendered = $this->renderTwigComponent(
            'Badge',
            [
                'variant' => 'green',
                'data-testid' => 'user-badge-self',
                'class' => 'text-nowrap',
            ],
            'You',
        );

        $html = (string) $rendered;

        self::assertStringContainsString('badge bg-green-lt text-green-lt-fg', $html);
        self::assertStringContainsString('text-nowrap', $html);
        self::assertStringContainsString('You', $html);
        self::assertStringNotContainsString('Pending', $html);
        self::assertGreaterThan(
            0,
            $rendered->crawler()->filter('[data-testid="user-badge-self"]')->count(),
        );
    }

    public function testUnknownVariantFallsBackWhenRendering(): void
    {
        $html = (string) $this->renderTwigComponent('Badge', [
            'variant' => 'notice',
            'label' => 'Other',
        ]);

        self::assertStringContainsString('badge bg-secondary-lt text-secondary-lt-fg', $html);
        self::assertStringNotContainsString('bg-notice-lt', $html);
        self::assertStringContainsString('Other', $html);
    }
}
