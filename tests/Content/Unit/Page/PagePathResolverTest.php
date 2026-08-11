<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Application\Slug\SlugGenerator;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class PagePathResolverTest extends TestCase
{
    private PagePathResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->resolver = new PagePathResolver(new SlugGenerator(new AsciiSlugger()));
    }

    public function testBuildPathForRootAndChildTranslation(): void
    {
        $rootPage = new Page();
        $rootTranslation = new PageTranslation()
            ->setLocale('en')
            ->setSlug('products');
        $rootPage->addTranslation($rootTranslation);
        $rootTranslation->setPath($this->resolver->buildPath($rootTranslation));

        $childPage = new Page()->setParent($rootPage);
        $childTranslation = new PageTranslation()
            ->setLocale('en')
            ->setSlug('hosting');
        $childPage->addTranslation($childTranslation);

        self::assertSame('/products', $rootTranslation->getPath());
        self::assertSame('/products/hosting', $this->resolver->buildPath($childTranslation));
    }

    public function testThrowsWhenParentTranslationMissing(): void
    {
        $rootPage = new Page();
        $childPage = new Page()->setParent($rootPage);
        $childTranslation = new PageTranslation()
            ->setLocale('en')
            ->setSlug('child');
        $childPage->addTranslation($childTranslation);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->buildPath($childTranslation);
    }

    public function testThrowsOnCycle(): void
    {
        $firstPage = new Page();
        $secondPage = new Page();
        $firstPage->setParent($secondPage);
        $secondPage->setParent($firstPage);

        $firstTranslation = new PageTranslation()->setLocale('en')->setSlug('first');
        $firstPage->addTranslation($firstTranslation);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->buildPath($firstTranslation);
    }

    public function testSynchronizePreservesManualSlugAndSetsPath(): void
    {
        $page = new Page();
        $translation = new PageTranslation()
            ->setLocale('en')
            ->setTitle('Test 123')
            ->setSlug('test-123');
        $page->addTranslation($translation);

        $this->resolver->synchronize($translation);

        self::assertSame('test-123', $translation->getSlug());
        self::assertSame('/test-123', $translation->getPath());
    }

    public function testSynchronizeTrimsWhitespaceFromManualSlug(): void
    {
        $page = new Page();
        $translation = new PageTranslation()
            ->setLocale('en')
            ->setTitle('Ignored')
            ->setSlug('  trimmed-page  ');
        $page->addTranslation($translation);

        $this->resolver->synchronize($translation);

        self::assertSame('trimmed-page', $translation->getSlug());
        self::assertSame('/trimmed-page', $translation->getPath());
    }

    public function testSynchronizeGeneratesSlugFromTitleWhenSlugIsEmpty(): void
    {
        $page = new Page();
        $translation = new PageTranslation()
            ->setLocale('en')
            ->setTitle('My Page Title')
            ->setSlug('');
        $page->addTranslation($translation);

        $this->resolver->synchronize($translation);

        self::assertSame('my-page-title', $translation->getSlug());
        self::assertSame('/my-page-title', $translation->getPath());
    }

    public function testSynchronizeTruncatesLongTitleWhenSlugIsEmpty(): void
    {
        $longTitle = str_repeat('segment-', 30).'tail';

        $page = new Page();
        $translation = new PageTranslation()
            ->setLocale('en')
            ->setTitle($longTitle)
            ->setSlug('');
        $page->addTranslation($translation);

        $this->resolver->synchronize($translation);

        self::assertLessThanOrEqual(SlugGenerator::MAX_LENGTH_PAGE, strlen((string) $translation->getSlug()));
        self::assertDoesNotMatchRegularExpression('/-$/', (string) $translation->getSlug());
    }
}
