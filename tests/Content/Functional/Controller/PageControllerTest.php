<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testPublishedPublicPageIsResolvedByPath(): void
    {
        $client = self::createClient();

        $parent = PageFactory::createOne([
            'title' => 'Produkte',
            'slug' => 'produkte',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Produkte</p>']]],
        ])->_real();

        PageFactory::createOne([
            'title' => 'Hosting',
            'slug' => 'hosting',
            'parent' => $parent,
            'status' => Page::STATUS_PUBLISHED,
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
            'status' => Page::STATUS_DRAFT,
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
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $client->request(Request::METHOD_GET, '/mitgliederbereich');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/login');

        $user = UserFactory::createOne()->_real();
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
            'status' => Page::STATUS_PUBLISHED,
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
            'status' => Page::STATUS_PUBLISHED,
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
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Öffentlich</p>']]],
        ])->_real();

        PageFactory::createOne([
            'title' => 'Geschwister',
            'slug' => 'geschwister',
            'parent' => $parent,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Geschwister</p>']]],
        ]);

        PageFactory::createOne([
            'title' => 'Nur Mitglieder',
            'slug' => 'nur-mitglieder',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $client->request(Request::METHOD_GET, '/oeffentlich');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="page-sidebar"]');
        self::assertSelectorTextContains('[data-testid="page-sidebar"]', 'Geschwister');
        self::assertSelectorTextNotContains('[data-testid="page-sidebar"]', 'Nur Mitglieder');
    }

    public function testSidebarShowsAuthenticatedPagesForLoggedInUser(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'Start',
            'slug' => 'start',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Start</p>']]],
        ]);

        PageFactory::createOne([
            'title' => 'Nur Mitglieder',
            'slug' => 'nur-mitglieder',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $user = UserFactory::createOne()->_real();
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/start');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="page-sidebar"]', 'Nur Mitglieder');
    }
}
