<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageTranslationResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use PHPUnit\Framework\TestCase;

final class PageTranslationResolverTest extends TestCase
{
    public function testResolveForDisplayPrefersRequestedPublishedLocale(): void
    {
        $page = new Page();
        $page->addTranslation($this->translation('en', PageTranslation::STATUS_PUBLISHED, 'English'));
        $page->addTranslation($this->translation('de', PageTranslation::STATUS_PUBLISHED, 'Deutsch'));

        $resolver = new PageTranslationResolver('en');
        $resolved = $resolver->resolveForDisplay($page, 'de');

        self::assertInstanceOf(PageTranslation::class, $resolved);
        self::assertSame('de', $resolved->getLocale());
        self::assertSame('Deutsch', $resolved->getTitle());
    }

    public function testResolveForDisplayFallsBackToDefaultWhenRequestedIsDraft(): void
    {
        $page = new Page();
        $page->addTranslation($this->translation('en', PageTranslation::STATUS_PUBLISHED, 'English'));
        $page->addTranslation($this->translation('de', PageTranslation::STATUS_DRAFT, 'Deutsch'));

        $resolver = new PageTranslationResolver('en');
        $resolved = $resolver->resolveForDisplay($page, 'de');

        self::assertInstanceOf(PageTranslation::class, $resolved);
        self::assertSame('en', $resolved->getLocale());
    }

    public function testResolveForDisplayReturnsNullWhenNothingPublished(): void
    {
        $page = new Page();
        $page->addTranslation($this->translation('en', PageTranslation::STATUS_DRAFT, 'English'));

        $resolver = new PageTranslationResolver('en');

        self::assertNull($resolver->resolveForDisplay($page, 'de'));
        self::assertNull($resolver->resolveForDisplay($page, 'en'));
    }

    public function testResolveExactDoesNotFallback(): void
    {
        $page = new Page();
        $page->addTranslation($this->translation('en', PageTranslation::STATUS_PUBLISHED, 'English'));

        $resolver = new PageTranslationResolver('en');

        self::assertNull($resolver->resolveExact($page, 'de'));
        self::assertSame('English', $resolver->resolveExact($page, 'en')?->getTitle());
    }

    private function translation(string $locale, string $status, string $title): PageTranslation
    {
        return new PageTranslation()
            ->setLocale($locale)
            ->setTitle($title)
            ->setSlug(strtolower($locale).'-'.strtolower($title))
            ->setPath('/'.strtolower($locale).'-'.strtolower($title))
            ->setStatus($status)
            ->setContent([]);
    }
}
