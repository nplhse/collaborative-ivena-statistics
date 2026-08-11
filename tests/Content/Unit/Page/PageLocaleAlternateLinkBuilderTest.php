<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\DTO\PageLocaleAlternateLink;
use App\Content\Application\Page\PageAccessChecker;
use App\Content\Application\Page\PageLocaleAlternateLinkBuilder;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PageLocaleAlternateLinkBuilderTest extends TestCase
{
    public function testBuildIncludesOnlyPublishedViewableTranslations(): void
    {
        $page = new Page();
        $page->setVisibility(Page::VISIBILITY_PUBLIC);

        $en = $this->translation('en', PageTranslation::STATUS_PUBLISHED, 'About', '/about');
        $de = $this->translation('de', PageTranslation::STATUS_PUBLISHED, 'Über uns', '/ueber-uns');
        $page->addTranslation($en);
        $page->addTranslation($de);

        $builder = $this->builder(canAuthenticate: false);
        $links = $builder->build($page, $en);

        self::assertCount(2, $links);
        self::assertSame(['en', 'de'], array_map(static fn (PageLocaleAlternateLink $l): string => $l->locale, $links));
        self::assertTrue($links[0]->current);
        self::assertFalse($links[1]->current);
        self::assertSame('/ueber-uns', $links[1]->path);
        self::assertSame(['en' => '/about', 'de' => '/ueber-uns'], $builder->localeSwitchTargets($links));
    }

    public function testBuildOmitsDraftAndInaccessibleTranslations(): void
    {
        $page = new Page();
        $page->setVisibility(Page::VISIBILITY_AUTHENTICATED);

        $en = $this->translation('en', PageTranslation::STATUS_PUBLISHED, 'About', '/about');
        $deDraft = $this->translation('de', PageTranslation::STATUS_DRAFT, 'Über uns', '/ueber-uns');
        $page->addTranslation($en);
        $page->addTranslation($deDraft);

        $builder = $this->builder(canAuthenticate: false);
        $links = $builder->build($page, $en);

        self::assertSame([], $links);
    }

    public function testBuildIncludesAuthenticatedPagesForLoggedInUser(): void
    {
        $page = new Page();
        $page->setVisibility(Page::VISIBILITY_AUTHENTICATED);

        $en = $this->translation('en', PageTranslation::STATUS_PUBLISHED, 'About', '/about');
        $de = $this->translation('de', PageTranslation::STATUS_PUBLISHED, 'Über uns', '/ueber-uns');
        $page->addTranslation($en);
        $page->addTranslation($de);

        $builder = $this->builder(canAuthenticate: true);
        $links = $builder->build($page, $en);

        self::assertCount(2, $links);
        self::assertSame(['en', 'de'], array_map(static fn (PageLocaleAlternateLink $l): string => $l->locale, $links));
    }

    public function testBuildOmitsTranslationsWithEmptyPath(): void
    {
        $page = new Page();
        $page->setVisibility(Page::VISIBILITY_PUBLIC);

        $en = $this->translation('en', PageTranslation::STATUS_PUBLISHED, 'About', '/about');
        $de = $this->translation('de', PageTranslation::STATUS_PUBLISHED, 'Über uns', '/');
        $page->addTranslation($en);
        $page->addTranslation($de);

        $builder = $this->builder(canAuthenticate: false);
        $links = $builder->build($page, $en);

        self::assertCount(1, $links);
        self::assertSame('en', $links[0]->locale);
    }

    public function testBuildReturnsEmptyWhenCurrentTranslationNotViewable(): void
    {
        $page = new Page();
        $page->setVisibility(Page::VISIBILITY_PUBLIC);
        $draft = $this->translation('en', PageTranslation::STATUS_DRAFT, 'Draft', '/draft');
        $page->addTranslation($draft);

        $builder = $this->builder(canAuthenticate: false);

        self::assertSame([], $builder->build($page, $draft));
    }

    private function builder(bool $canAuthenticate): PageLocaleAlternateLinkBuilder
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($canAuthenticate);

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $name, array $params): string => '/'.$params['path'],
        );

        return new PageLocaleAlternateLinkBuilder(new PageAccessChecker($security), $urls);
    }

    private function translation(string $locale, string $status, string $title, string $path): PageTranslation
    {
        return new PageTranslation()
            ->setLocale($locale)
            ->setTitle($title)
            ->setSlug(ltrim($path, '/'))
            ->setPath($path)
            ->setStatus($status)
            ->setContent([]);
    }
}
