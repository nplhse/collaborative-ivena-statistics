<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class SitemapControllerTest extends WebTestCase
{
    use Factories;

    public function testGuestCanViewPublicSitemapPage(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/sitemap');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Sitemap');
        self::assertSelectorExists('nav.sitemap .sitemap__more');
        self::assertSelectorExists('.sitemap__links a.sitemap-tile--link[data-testid="sitemap-section-blog"][href="/blog"]');
        self::assertSelectorTextContains('[data-testid="sitemap-section-footer-more"]', 'Cookie settings');
        self::assertSelectorNotExists('[data-testid="sitemap-section-explore"]');
        self::assertSelectorNotExists('[data-testid="sitemap-section-statistics"]');
        self::assertStringNotContainsString('sitemap.title', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Overview of available pages', (string) $client->getResponse()->getContent());
    }

    public function testSitemapRendersNestedContentPageTree(): void
    {
        $client = self::createClient();
        $this->seedContentPages();
        $client->request(Request::METHOD_GET, '/sitemap');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="sitemap-section-pages"]', 'Guides');
        self::assertSelectorTextContains('[data-testid="sitemap-section-pages"] .sitemap-tile__list--nested', 'Child page');
        self::assertSelectorTextNotContains('[data-testid="sitemap-section-pages"]', 'Members only');
    }

    public function testParticipantSeesExploreSectionOnSitemap(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $client->request(Request::METHOD_GET, '/sitemap');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sitemap__main [data-testid="sitemap-section-explore"] + [data-testid="sitemap-section-statistics"]');
        self::assertSelectorTextContains('[data-testid="sitemap-section-statistics"]', 'Benchmarking');
        self::assertSelectorExists('.sitemap__aside [data-testid="sitemap-section-footer-project"]');
        self::assertSelectorExists('.sitemap__main .sitemap__more [data-testid="sitemap-section-tools"]');
        self::assertSelectorExists('.sitemap__aside .sitemap__links [data-testid="sitemap-section-import"]');
        self::assertSelectorNotExists('[data-testid="sitemap-section-account"]');
    }

    public function testFooterContainsSitemapLink(): void
    {
        $client = self::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href="/sitemap"]')->count());
    }

    private function seedContentPages(): void
    {
        $guidesParent = PageFactory::createOne([
            'title' => 'Guides',
            'slug' => 'guides',
            'path' => '/guides',
            'key' => null,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 1,
        ]);

        PageFactory::createOne([
            'title' => 'Custom guide',
            'slug' => 'custom-guide',
            'path' => '/guides/custom-guide',
            'parent' => $guidesParent,
            'key' => null,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 1,
        ]);

        PageFactory::createOne([
            'title' => 'Child page',
            'slug' => 'child-page',
            'path' => '/guides/child-page',
            'parent' => $guidesParent,
            'key' => null,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 2,
        ]);

        PageFactory::createOne([
            'title' => 'Members only',
            'slug' => 'members-only',
            'path' => '/members-only',
            'key' => null,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);
    }
}
