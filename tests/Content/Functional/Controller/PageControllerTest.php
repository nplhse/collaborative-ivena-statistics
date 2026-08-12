<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\Shared\Application\Locale\SupportedLocales;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageControllerTest extends WebTestCase
{
    use Factories;

    public function testPublishedPublicPageIsResolvedByPath(): void
    {
        $client = self::createClient();

        $parent = PageFactory::createOne([
            'title' => 'Produkte',
            'slug' => 'produkte',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Produkte</p>']]],
        ]);

        PageFactory::createOne([
            'title' => 'Hosting',
            'slug' => 'hosting',
            'parent' => $parent,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Hosting</p>']]],
        ]);

        $client->request(Request::METHOD_GET, '/produkte/hosting');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Hosting');
        self::assertSelectorExists('nav[aria-label="breadcrumb"]');
        self::assertSelectorTextContains('nav[aria-label="breadcrumb"]', 'Produkte');
    }

    public function testDraftPageIsNotPubliclyVisible(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'Intern',
            'slug' => 'intern',
            'status' => PageTranslation::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $client->request(Request::METHOD_GET, '/intern');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAuthenticatedPageRequiresLogin(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'Mitgliederbereich',
            'slug' => 'mitgliederbereich',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $client->request(Request::METHOD_GET, '/mitgliederbereich');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/login');

        $user = UserFactory::createOne();
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/mitgliederbereich');
        self::assertResponseIsSuccessful();
    }

    public function testPageRendersNewBlockTypesInSharedCard(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'Demo Blocks',
            'slug' => 'demo-blocks',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [
                [
                    'type' => 'headline',
                    'data' => [
                        'text' => 'Demo Headline',
                        'level' => 'h2',
                    ],
                ],
                [
                    'type' => 'highlight',
                    'data' => [
                        'variant' => 'warning',
                        'title' => 'Important',
                        'html' => '<p>Warning content</p>',
                    ],
                ],
                [
                    'type' => 'image',
                    'data' => [
                        'src' => '/uploads/demo.jpg',
                        'alt' => 'Demo',
                        'size' => 'md',
                        'float' => 'left',
                    ],
                ],
                [
                    'type' => 'richtext',
                    'data' => ['html' => '<p>Wrapped text</p>'],
                ],
                [
                    'type' => 'accordion',
                    'data' => [
                        'items' => [
                            [
                                'title' => 'FAQ question',
                                'html' => '<p>FAQ answer</p>',
                                'openByDefault' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $client->request(Request::METHOD_GET, '/demo-blocks');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('article.card .page-content-blocks');
        self::assertSelectorTextContains('h2.page-content-headline', 'Demo Headline');
        self::assertSelectorExists('.page-content-highlight.alert-warning');
        self::assertSelectorExists('.page-content-image--size-md');
        self::assertSelectorExists('.page-content-image--float-left');
        self::assertSelectorExists('.page-content-accordion .accordion-button');
    }

    public function testImageBlockWithAutoSizeRendersNaturalWidthClass(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'Auto Image',
            'slug' => 'auto-image',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [
                [
                    'type' => 'image',
                    'data' => [
                        'src' => '/uploads/demo.jpg',
                        'alt' => 'Demo',
                        'size' => 'auto',
                        'width' => 320,
                        'height' => 200,
                    ],
                ],
            ],
        ]);

        $client->request(Request::METHOD_GET, '/auto-image');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.page-content-image--size-auto');
        self::assertSelectorExists('img[width="320"][height="200"]');
    }

    public function testSidebarShowsOnlyPublicPagesForGuest(): void
    {
        $client = self::createClient();

        $parent = PageFactory::createOne([
            'title' => 'Öffentlich',
            'slug' => 'oeffentlich',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Öffentlich</p>']]],
        ]);

        PageFactory::createOne([
            'title' => 'Geschwister',
            'slug' => 'geschwister',
            'parent' => $parent,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Geschwister</p>']]],
        ]);

        PageFactory::createOne([
            'title' => 'Nur Mitglieder',
            'slug' => 'nur-mitglieder',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $client->request(Request::METHOD_GET, '/oeffentlich');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="page-sidebar"]');
        self::assertSelectorTextContains('[data-testid="page-sidebar"]', 'Geschwister');
        self::assertSelectorTextNotContains('[data-testid="page-sidebar"]', 'Nur Mitglieder');
    }

    public function testPublishedLanguageAlternatesAreLinkedAndLocaleSwitchRedirectsToSiblingPath(): void
    {
        $client = self::createClient();

        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'About',
            'slug' => 'about-root',
            'path' => '/about-root-legacy',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'About us',
            'slug' => 'about-us',
            'path' => '/about-us',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'content' => [['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>About EN</p>']]],
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'Über uns',
            'slug' => 'ueber-uns',
            'path' => '/ueber-uns',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'content' => [['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>About DE</p>']]],
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/about-us');
        self::assertResponseIsSuccessful();

        $deLink = $crawler->filter('a.dropdown-item[href*="/locale/switch/de"]')->link();
        self::assertStringContainsString('_target_path=/ueber-uns', $deLink->getUri());

        $client->click($deLink);

        self::assertResponseRedirects('/ueber-uns');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.fw-bold', 'Über uns');
        self::assertSelectorTextContains('.page-content-blocks', 'About DE');
    }

    public function testSidebarShowsAuthenticatedPagesForLoggedInUser(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'Start',
            'slug' => 'start',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Start</p>']]],
        ]);

        PageFactory::createOne([
            'title' => 'Nur Mitglieder',
            'slug' => 'nur-mitglieder',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $user = UserFactory::createOne();
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/start');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="page-sidebar"]', 'Nur Mitglieder');
    }

    public function testTableOfContentsIsRenderedWhenEnabledAndHeadingsExist(): void
    {
        $client = self::createClient();

        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Guide',
            'slug' => 'guide',
            'path' => '/guide',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Guide',
            'slug' => 'guide',
            'path' => '/guide',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'showToc' => true,
            'content' => [
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'Getting started', 'level' => 'h2'],
                ],
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => ['html' => '<h3>First steps</h3><p>Hello</p>'],
                ],
            ],
        ]);

        $client->request(Request::METHOD_GET, '/guide');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="page-toc"]');
        self::assertSelectorExists('[data-testid="page-toc"] a[href="#getting-started"]');
        self::assertSelectorExists('[data-testid="page-toc"] a[href="#first-steps"]');
        self::assertSelectorExists('h2#getting-started.page-content-headline');
        self::assertSelectorExists('h3#first-steps');
    }

    public function testTableOfContentsIsHiddenWhenDisabledOrWithoutHeadings(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'No Toc Flag',
            'slug' => 'no-toc-flag',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [
                [
                    'type' => 'headline',
                    'data' => ['text' => 'Heading', 'level' => 'h2'],
                ],
            ],
        ]);

        $pageWithoutHeadings = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Empty Toc',
            'slug' => 'empty-toc',
            'path' => '/empty-toc',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageTranslationFactory::createOne([
            'page' => $pageWithoutHeadings,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Empty Toc',
            'slug' => 'empty-toc',
            'path' => '/empty-toc',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'showToc' => true,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => ['html' => '<p>No headings here</p>'],
                ],
            ],
        ]);

        $client->request(Request::METHOD_GET, '/no-toc-flag');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="page-toc"]');

        $client->request(Request::METHOD_GET, '/empty-toc');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="page-toc"]');
    }

    public function testAdminSeesEditShortcutToTranslationBackend(): void
    {
        $client = self::createClient();

        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Editable',
            'slug' => 'editable-page',
            'path' => '/editable-page',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $translation = PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Editable',
            'slug' => 'editable-page',
            'path' => '/editable-page',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'content' => [['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Edit me</p>']]],
        ]);

        $client->request(Request::METHOD_GET, '/editable-page');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="page-edit-action"]');

        $user = UserFactory::createOne();
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/editable-page');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="page-edit-action"]');

        $admin = UserFactory::new()->asAdmin()->create();
        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/editable-page');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="page-edit-action"]');
        self::assertSelectorExists(sprintf(
            '[data-testid="page-edit-action"][href="/admin/page-translation/%d/edit"]',
            $translation->getId(),
        ));
    }
}
