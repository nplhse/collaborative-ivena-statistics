<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ActiveFiltersComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTitleAndBadgesInsideAlert(): void
    {
        $html = (string) $this->renderTwigComponent('ActiveFilters', [
            'badges' => [
                ['label' => 'Hospital', 'value' => 'Kiel'],
            ],
        ]);

        self::assertStringContainsString('alert alert-info', $html);
        self::assertStringContainsString('Active filters', $html);
        self::assertStringContainsString('badge bg-blue-lt', $html);
        self::assertStringContainsString('Hospital:', $html);
        self::assertStringContainsString('Kiel', $html);
    }

    public function testPassesThroughTestIdAttribute(): void
    {
        $rendered = $this->renderTwigComponent('ActiveFilters', [
            'badges' => [
                ['label' => 'Status', 'value' => 'Open'],
            ],
            'data-testid' => 'statistics-filters-active',
        ]);

        self::assertGreaterThan(
            0,
            $rendered->crawler()->filter('[data-testid="statistics-filters-active"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $rendered->crawler()->filter('[data-testid="statistics-filters-active"] .alert-info .badge')->count(),
        );
    }

    public function testRendersNothingWhenBadgesAreEmpty(): void
    {
        $html = (string) $this->renderTwigComponent('ActiveFilters', [
            'badges' => [],
        ]);

        self::assertSame('', trim($html));
    }
}
