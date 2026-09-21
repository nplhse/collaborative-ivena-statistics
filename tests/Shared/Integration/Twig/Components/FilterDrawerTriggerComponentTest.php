<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class FilterDrawerTriggerComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersOpenButtonWithoutClearWhenIdle(): void
    {
        $html = (string) $this->renderTwigComponent('FilterDrawerTrigger', [
            'drawerId' => 'hospital-filters',
            'openTestId' => 'hospital-filters-drawer-trigger',
        ]);

        self::assertStringContainsString('data-bs-toggle="offcanvas"', $html);
        self::assertStringContainsString('data-bs-target="#hospital-filters"', $html);
        self::assertStringContainsString('aria-controls="hospital-filters"', $html);
        self::assertStringContainsString('data-testid="hospital-filters-drawer-trigger"', $html);
        self::assertStringContainsString('Filters', $html);
        self::assertStringNotContainsString('badge', $html);
        self::assertStringNotContainsString('btn-icon', $html);
    }

    public function testRendersCountBadgeAndClearButtonWhenFiltersAreActive(): void
    {
        $rendered = $this->renderTwigComponent('FilterDrawerTrigger', [
            'drawerId' => 'user-filters',
            'activeCount' => 2,
            'resetUrl' => '/explore/user',
            'openTestId' => 'user-directory-filters-toggle',
            'clearTestId' => 'user-directory-filters-reset',
            'countTestId' => 'user-directory-active-filter-count',
        ]);
        $crawler = $rendered->crawler();

        self::assertCount(1, $crawler->filter('[data-testid="user-directory-filters-toggle"]'));
        self::assertCount(1, $crawler->filter('[data-testid="user-directory-active-filter-count"]'));
        self::assertSame('2', trim($crawler->filter('[data-testid="user-directory-active-filter-count"]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid="user-directory-filters-reset"]'));
        self::assertSame('/explore/user', $crawler->filter('[data-testid="user-directory-filters-reset"]')->attr('href'));
    }
}
