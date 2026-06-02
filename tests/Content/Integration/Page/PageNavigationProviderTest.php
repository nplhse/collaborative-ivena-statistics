<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageNavigationProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageNavigationProviderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private PageNavigationProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::getContainer()->get(PageNavigationProvider::class);
    }

    public function testGetUrlByKeyUsesPagePathNotSlug(): void
    {
        $parent = PageFactory::createOne([
            'slug' => 'legal',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ])->_real();

        PageFactory::createOne([
            'parent' => $parent,
            'slug' => 'imprint-custom',
            'key' => PageKey::Imprint,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $url = $this->provider->getUrlByKey(PageKey::Imprint);

        self::assertNotNull($url);
        self::assertStringContainsString('/legal/imprint-custom', $url);
        self::assertStringNotContainsString('/imprint-slug', $url);
    }

    public function testGetUrlByKeyReturnsNullForDraftPage(): void
    {
        PageFactory::createOne([
            'slug' => 'features-draft',
            'path' => '/features-draft',
            'key' => PageKey::Features,
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        self::assertNull($this->provider->getUrlByKey(PageKey::Features));
    }

    public function testGetUrlByKeyReturnsNullForAuthenticatedOnlyPageForGuest(): void
    {
        PageFactory::createOne([
            'slug' => 'about-auth',
            'path' => '/about-auth',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        self::assertNull($this->provider->getUrlByKey(PageKey::About));
    }

    public function testGetHeaderPagesOnlyIncludesVisiblePublishedPages(): void
    {
        PageFactory::createOne([
            'slug' => 'about-public',
            'path' => '/about-public',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'About us',
        ]);

        PageFactory::createOne([
            'slug' => 'features-draft',
            'path' => '/features-draft',
            'key' => PageKey::Features,
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'Features',
        ]);

        $headerPages = $this->provider->getHeaderPages();

        self::assertCount(1, $headerPages);
        self::assertSame(PageKey::About, $headerPages[0]->key);
        self::assertSame('About us', $headerPages[0]->label);
    }

    public function testGetFooterPagesRespectsKeyOrder(): void
    {
        PageFactory::createOne([
            'slug' => 'terms-footer',
            'path' => '/terms-footer',
            'key' => PageKey::Terms,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'imprint-footer',
            'path' => '/imprint-footer',
            'key' => PageKey::Imprint,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $keys = array_map(
            static fn (\App\Content\Application\Page\DTO\PageNavigationLink $link): PageKey => $link->key,
            $this->provider->getFooterPages(),
        );

        self::assertSame([PageKey::Imprint, PageKey::Terms], $keys);
    }
}
