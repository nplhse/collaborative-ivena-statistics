<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\Shared\Application\Locale\SupportedLocales;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanOpenPageIndexAndNewForm(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/page');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Pages');

        $client->request(Request::METHOD_GET, '/admin/page/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Create Page');
    }

    public function testNonAdminUserGetsForbiddenOnPageIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'page-regular-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/page');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAddTranslationLinkFromPageDetailOpensNewFormWithoutStaleEntityId(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-add-tr-'.bin2hex(random_bytes(4)),
            ])
        ;

        // English exists via factory; German is missing → "New" link for de must not reuse the Page entityId.
        $page = PageFactory::createOne([
            'title' => 'About us',
            'slug' => 'about-us-admin',
            'path' => '/about-us-admin',
            'status' => Page::STATUS_PUBLISHED,
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/page/%d', $page->getId()));
        self::assertResponseIsSuccessful();

        $links = $crawler->filter('a')->each(static fn ($node): string => (string) $node->attr('href'));
        $newLinks = array_values(array_filter(
            $links,
            static fn (string $href): bool => str_contains($href, '/page-translation/new'),
        ));
        self::assertNotEmpty($newLinks, 'Expected a New translation link on page detail. Hrefs: '.implode(' | ', $links));

        $href = $newLinks[0];
        self::assertStringNotContainsString('/page-translation/'.(string) $page->getId(), $href);
        self::assertDoesNotMatchRegularExpression('#entityId='.(string) $page->getId().'(?:&|$)#', $href);
        self::assertMatchesRegularExpression('#(?:\?|&)pageId='.(string) $page->getId().'(?:&|$)#', $href);

        $client->request(Request::METHOD_GET, $href);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Create');
    }

    public function testPageDetailShowsPublicLinksForPublishedTranslations(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-pub-links-'.bin2hex(random_bytes(4)),
            ])
        ;

        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Multilingual',
            'slug' => 'multilingual-root',
            'path' => '/multilingual-root-legacy',
            'status' => Page::STATUS_PUBLISHED,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'About EN',
            'slug' => 'about-en-admin',
            'path' => '/about-en-admin',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'Über uns DE',
            'slug' => 'ueber-uns-de-admin',
            'path' => '/ueber-uns-de-admin',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/page/%d', $page->getId()));
        self::assertResponseIsSuccessful();

        $publicLinks = $crawler->filter('a[href="/about-en-admin"], a[href="/ueber-uns-de-admin"]');
        self::assertCount(2, $publicLinks);
        self::assertStringContainsString('About EN', $crawler->filter('body')->text());
        self::assertStringContainsString('Über uns DE', $crawler->filter('body')->text());
    }

    public function testPageTranslationContentBlockOrderIsPersistedWhenSubmittedInReverseOrder(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-reorder-'.bin2hex(random_bytes(4)),
            ])
        ;

        $page = PageFactory::createOne([
            'title' => 'Reorder Test',
            'slug' => 'reorder-test',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'First block', 'level' => 'h2'],
                ],
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'Second block', 'level' => 'h2'],
                ],
            ],
        ]);

        $translation = $page->translation('en');
        self::assertInstanceOf(PageTranslation::class, $translation);

        $client->loginUser($admin);
        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/page-translation/%d/edit', $translation->getId()),
        );

        self::assertResponseIsSuccessful();

        $saveButton = $crawler->selectButton('Save changes');
        if (0 === $saveButton->count()) {
            $saveButton = $crawler->selectButton('Save');
        }

        $form = $saveButton->form();
        $form['PageTranslation[content][0][type]'] = 'headline';
        $form['PageTranslation[content][0][enabled]'] = '1';
        $form['PageTranslation[content][0][data][text]'] = 'Second block';
        $form['PageTranslation[content][0][data][level]'] = 'h2';
        $form['PageTranslation[content][1][type]'] = 'headline';
        $form['PageTranslation[content][1][enabled]'] = '1';
        $form['PageTranslation[content][1][data][text]'] = 'First block';
        $form['PageTranslation[content][1][data][level]'] = 'h2';

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $updatedPage = PageFactory::repository()->find($page->getId());
        self::assertInstanceOf(Page::class, $updatedPage);
        $updated = $updatedPage->translation('en');
        self::assertInstanceOf(PageTranslation::class, $updated);

        $content = $updated->getContent();
        self::assertSame('Second block', $content[0]['data']['text'] ?? null);
        self::assertSame('First block', $content[1]['data']['text'] ?? null);
    }

    public function testTranslationDetailShowsRelatedPageAndSiblingLinks(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-tr-rel-'.bin2hex(random_bytes(4)),
            ])
        ;

        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Relations Page',
            'slug' => 'relations-page',
            'path' => '/relations-page-legacy',
        ]);

        $en = PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Relations EN',
            'slug' => 'relations-en',
            'path' => '/relations-en',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'Relations DE',
            'slug' => 'relations-de',
            'path' => '/relations-de',
            'status' => PageTranslation::STATUS_DRAFT,
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/page-translation/%d', $en->getId()));
        self::assertResponseIsSuccessful();

        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Relations DE', $body);
        self::assertStringContainsString((string) $page->getId(), $body);

        $pageDetailLinks = $crawler->filter(sprintf('a[href*="/admin/page/%d"]', $page->getId()));
        self::assertGreaterThan(0, $pageDetailLinks->count());
    }
}
