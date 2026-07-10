<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageNavigationProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageNavigationProviderTest extends KernelTestCase
{
    use Factories;

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
        ]);

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

    public function testGetHeaderPagesHidesAboutAndFeaturesForAuthenticatedUsers(): void
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
            'slug' => 'features-public',
            'path' => '/features-public',
            'key' => PageKey::Features,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'Features',
        ]);

        PageFactory::createOne([
            'slug' => 'faq-public',
            'path' => '/faq-public',
            'key' => PageKey::Faq,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'FAQ',
        ]);

        $guestHeaderPages = $this->provider->getHeaderPages();
        self::assertCount(3, $guestHeaderPages);
        self::assertSame(
            [PageKey::About, PageKey::Features, PageKey::Faq],
            array_map(static fn (\App\Content\Application\Page\DTO\PageNavigationLink $link): PageKey => $link->key, $guestHeaderPages),
        );

        $user = \App\User\Domain\Factory\UserFactory::createOne(['username' => 'nav-user']);
        $tokenStorage = self::getContainer()->get(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));

        $authenticatedHeaderPages = $this->provider->getHeaderPages();
        self::assertCount(1, $authenticatedHeaderPages);
        self::assertSame(PageKey::Faq, $authenticatedHeaderPages[0]->key);
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

    public function testGetVisiblePublishedPageLinksIncludesPagesWithoutKeyAndRespectsVisibility(): void
    {
        PageFactory::createOne([
            'title' => 'Public guide',
            'slug' => 'public-guide',
            'path' => '/guides/public-guide',
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'title' => 'Members only',
            'slug' => 'members-only',
            'path' => '/members-only',
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $guestLinks = $this->provider->getVisiblePublishedPageLinks();
        $guestLabels = array_map(static fn (\App\Content\Application\Page\DTO\PublishedPageLink $link): string => $link->label, $guestLinks);

        self::assertContains('Public guide', $guestLabels);
        self::assertNotContains('Members only', $guestLabels);

        $user = \App\User\Domain\Factory\UserFactory::createOne(['username' => 'page-nav-user']);
        $tokenStorage = self::getContainer()->get(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));

        $authenticatedLabels = array_map(
            static fn (\App\Content\Application\Page\DTO\PublishedPageLink $link): string => $link->label,
            $this->provider->getVisiblePublishedPageLinks(),
        );

        self::assertContains('Public guide', $authenticatedLabels);
        self::assertContains('Members only', $authenticatedLabels);
    }

    public function testGetVisiblePublishedPageTreePreservesHierarchy(): void
    {
        $parent = PageFactory::createOne([
            'title' => 'Documentation',
            'slug' => 'documentation',
            'path' => '/documentation',
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 1,
        ]);

        PageFactory::createOne([
            'title' => 'Getting started',
            'slug' => 'getting-started',
            'path' => '/documentation/getting-started',
            'parent' => $parent,
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 1,
        ]);

        $tree = $this->provider->getVisiblePublishedPageTree();

        self::assertCount(1, $tree);
        self::assertSame('Documentation', $tree[0]->label);
        self::assertCount(1, $tree[0]->children);
        self::assertSame('Getting started', $tree[0]->children[0]->label);
    }
}
