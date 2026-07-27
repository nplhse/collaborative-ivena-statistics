<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Sitemap;

use App\Content\Application\Sitemap\XmlSitemapProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class XmlSitemapProviderTest extends KernelTestCase
{
    use Factories;

    private XmlSitemapProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::getContainer()->get(XmlSitemapProvider::class);
    }

    public function testGetUrlsIncludesStaticPublicContentAndExcludesNonPublicEntries(): void
    {
        PageFactory::createOne([
            'slug' => 'sitemap-public-page',
            'path' => '/sitemap-public-page',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'sitemap-auth-page',
            'path' => '/sitemap-auth-page',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        PageFactory::createOne([
            'slug' => 'sitemap-draft-page',
            'path' => '/sitemap-draft-page',
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PostFactory::createOne([
            'slug' => 'sitemap-visible-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        PostFactory::createOne([
            'slug' => 'sitemap-future-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('+1 day'),
        ]);

        PostFactory::createOne([
            'slug' => 'sitemap-draft-post',
            'status' => PostStatus::DRAFT,
            'publishedAt' => new \DateTimeImmutable('-1 day'),
        ]);

        $locs = array_map(
            static fn (\App\Content\Application\Sitemap\DTO\XmlSitemapUrl $url): string => $url->loc,
            $this->provider->getUrls(),
        );

        self::assertTrue($this->containsPath($locs, '/'));
        self::assertTrue($this->containsPath($locs, '/blog'));
        self::assertTrue($this->containsPath($locs, '/sitemap-public-page'));
        self::assertTrue($this->containsPath($locs, '/blog/sitemap-visible-post'));

        self::assertFalse($this->containsPath($locs, '/sitemap-auth-page'));
        self::assertFalse($this->containsPath($locs, '/sitemap-draft-page'));
        self::assertFalse($this->containsPath($locs, '/blog/sitemap-future-post'));
        self::assertFalse($this->containsPath($locs, '/blog/sitemap-draft-post'));
        self::assertFalse($this->containsPath($locs, '/login'));
        self::assertFalse($this->containsPath($locs, '/statistics'));
    }

    /**
     * @param list<string> $locs
     */
    private function containsPath(array $locs, string $path): bool
    {
        foreach ($locs as $loc) {
            $parsedPath = parse_url($loc, \PHP_URL_PATH);
            if ($path === $parsedPath) {
                return true;
            }
        }

        return false;
    }
}
