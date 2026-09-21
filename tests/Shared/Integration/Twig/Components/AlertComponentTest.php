<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use App\Shared\UI\Twig\Components\Alert;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class AlertComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersMessageAndDefaultInfoVariant(): void
    {
        $html = (string) $this->renderTwigComponent('Alert', [
            'message' => 'Search is active',
        ]);

        self::assertStringContainsString('alert alert-info', $html);
        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('Search is active', $html);
        self::assertStringContainsString('alert-icon', $html);
        self::assertStringContainsString('icon alert-icon', $html);
        self::assertStringNotContainsString('w-4 h-4', $html);
        self::assertGreaterThan(0, substr_count($html, '<svg'));
    }

    public function testRendersContentSlotInsteadOfOnlyMessageString(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Alert',
            ['type' => 'warning', 'title' => 'Check filters'],
            '<a class="alert-link" href="/filters">Adjust filters</a>',
        );

        self::assertStringContainsString('alert alert-warning', $html);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('alert-heading', $html);
        self::assertStringContainsString('Check filters', $html);
        self::assertStringContainsString('Adjust filters', $html);
        self::assertStringContainsString('alert-link', $html);
    }

    public function testRendersDismissibleCloseButton(): void
    {
        $html = (string) $this->renderTwigComponent('Alert', [
            'type' => 'success',
            'message' => 'Saved',
            'dismissible' => true,
            'important' => true,
        ]);

        self::assertStringContainsString('alert-dismissible', $html);
        self::assertStringContainsString('alert-important', $html);
        self::assertStringContainsString('btn-close', $html);
        self::assertStringContainsString('data-bs-dismiss="alert"', $html);
        self::assertStringContainsString('aria-label="Close"', $html);
    }

    public function testPassesThroughTestIdAttribute(): void
    {
        $rendered = $this->renderTwigComponent('Alert', [
            'type' => 'info',
            'message' => 'Notice',
            'data-testid' => 'stats-insights-compare-overlap-notice',
        ]);

        self::assertGreaterThan(
            0,
            $rendered->crawler()->filter('[data-testid="stats-insights-compare-overlap-notice"]')->count(),
        );
    }

    public function testMapsErrorAliasWhenRendering(): void
    {
        $component = $this->mountTwigComponent('Alert', [
            'type' => 'error',
            'message' => 'Boom',
        ]);

        self::assertInstanceOf(Alert::class, $component);
        self::assertSame('danger', $component->getVariant());
        self::assertSame('alert', $component->getRole());

        $html = (string) $this->renderTwigComponent('Alert', [
            'type' => 'error',
            'message' => 'Boom',
        ]);

        self::assertStringContainsString('alert-danger', $html);
        self::assertStringNotContainsString('alert-error', $html);
    }

    public function testIconNoneOmitsIconMarkup(): void
    {
        $html = (string) $this->renderTwigComponent('Alert', [
            'type' => 'info',
            'message' => 'Plain',
            'icon' => 'none',
        ]);

        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('alert-icon', $html);
        self::assertStringContainsString('Plain', $html);
    }

    public function testRendersActionsSlot(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Alert',
            ['type' => 'danger', 'title' => 'Save failed'],
            'Could not save your changes.',
            ['actions' => '<a class="alert-action" href="/retry">Retry</a>'],
        );

        self::assertStringContainsString('alert-heading', $html);
        self::assertStringContainsString('Save failed', $html);
        self::assertStringContainsString('Could not save your changes.', $html);
        self::assertStringContainsString('alert-action', $html);
        self::assertStringContainsString('Retry', $html);
    }
}
