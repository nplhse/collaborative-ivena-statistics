<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\Breadcrumbs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BreadcrumbsTest extends TestCase
{
    public function testSitemapTitleIsTranslatedFromSharedDomain(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('app_default')->willReturn('/');

        $component = new Breadcrumbs($urlGenerator);
        $component->items = [
            ['label' => 'sitemap.title'],
        ];

        $items = $component->getFullItems();

        self::assertCount(2, $items);
        self::assertSame('sitemap.title', $items[1]['label']);
        self::assertSame('shared', $items[1]['label_domain']);
        self::assertNotSame(false, $items[1]['translatable'] ?? true);
    }

    public function testCookiePreferencesTitleIsTranslatedFromSharedDomain(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('app_default')->willReturn('/');

        $component = new Breadcrumbs($urlGenerator);
        $component->items = [
            ['label' => 'cookie.preferences.title'],
        ];

        $items = $component->getFullItems();

        self::assertSame('shared', $items[1]['label_domain']);
        self::assertNotSame(false, $items[1]['translatable'] ?? true);
    }
}
