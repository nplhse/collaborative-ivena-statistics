<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Application\Slug\SlugGenerator;
use App\Content\Domain\Entity\Page;
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

    public function testBuildPathForRootAndChildPage(): void
    {
        $root = new Page()->setSlug('produkte');
        $root->setPath($this->resolver->buildPath($root));

        $child = new Page()
            ->setSlug('hosting')
            ->setParent($root);

        self::assertSame('/produkte', $root->getPath());
        self::assertSame('/produkte/hosting', $this->resolver->buildPath($child));
    }

    public function testThrowsOnCycle(): void
    {
        $first = new Page()->setSlug('first');
        $second = new Page()->setSlug('second');

        $first->setParent($second);
        $second->setParent($first);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->buildPath($first);
    }

    public function testSynchronizePreservesManualSlugAndSetsPath(): void
    {
        $page = new Page()
            ->setTitle('Test 123')
            ->setSlug('test-123');

        $this->resolver->synchronize($page);

        self::assertSame('test-123', $page->getSlug());
        self::assertSame('/test-123', $page->getPath());
    }

    public function testSynchronizeTrimsWhitespaceFromManualSlug(): void
    {
        $page = new Page()
            ->setTitle('Ignored')
            ->setSlug('  trimmed-page  ');

        $this->resolver->synchronize($page);

        self::assertSame('trimmed-page', $page->getSlug());
        self::assertSame('/trimmed-page', $page->getPath());
    }

    public function testSynchronizeGeneratesSlugFromTitleWhenSlugIsEmpty(): void
    {
        $page = new Page()
            ->setTitle('My Page Title')
            ->setSlug('');

        $this->resolver->synchronize($page);

        self::assertSame('my-page-title', $page->getSlug());
        self::assertSame('/my-page-title', $page->getPath());
    }

    public function testSynchronizeTruncatesLongTitleWhenSlugIsEmpty(): void
    {
        $longTitle = str_repeat('segment-', 30).'tail';

        $page = new Page()
            ->setTitle($longTitle)
            ->setSlug('');

        $this->resolver->synchronize($page);

        self::assertLessThanOrEqual(SlugGenerator::MAX_LENGTH_PAGE, strlen((string) $page->getSlug()));
        self::assertDoesNotMatchRegularExpression('/-$/', (string) $page->getSlug());
    }
}
