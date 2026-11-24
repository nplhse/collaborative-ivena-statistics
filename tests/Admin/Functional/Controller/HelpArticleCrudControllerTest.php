<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\HelpFeedback;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageType;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Tests\Support\Content\HelpPages;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Help articles have their own admin list (#596); the page list leaves them out.
 */
#[ResetDatabase]
final class HelpArticleCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testHelpListShowsArticlesWithAnswersAndThePageListLeavesThemOut(): void
    {
        $client = self::createClient();
        PageFactory::createOne(['title' => 'About', 'slug' => 'about', 'path' => '/about']);
        $root = HelpPages::root();
        $article = HelpPages::article($root, 'Rejected rows', '/help/rejected-rows', '<p>Text</p>');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([true, true, false] as $helpful) {
            $entityManager->persist(new HelpFeedback($article, UserFactory::createOne(), $helpful, 'en', new \DateTimeImmutable('2026-11-21')));
        }
        $entityManager->flush();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, '/admin/help-article');
        self::assertResponseIsSuccessful();
        self::assertSame(['Rejected rows'], $crawler->filter('td[data-column="articleTitle"]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('👍 2 · 👎 1', trim($crawler->filter('td[data-column="helpfulVotes"]')->text()));

        $crawler = $client->request(Request::METHOD_GET, '/admin/page');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Rejected rows', $crawler->filter('table')->text());
        self::assertStringContainsString('Help', $crawler->filter('table')->text(), 'the help start page stays a page');
    }

    public function testNewArticlesStartBelowTheHelpStartPage(): void
    {
        $client = self::createClient();
        $root = HelpPages::root();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, '/admin/help-article/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form[name="Page"]')->form([
            'Page[helpKey]' => 'import.export',
            'Page[sortOrder]' => '3',
        ]));
        self::assertResponseRedirects();

        $page = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Page::class)->findOneBy(['helpKey' => 'import.export']);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame(PageType::Help, $page->getType());
        self::assertSame($root->getId(), $page->getParent()?->getId());
    }

    public function testTheIconIsCheckedWhenSaving(): void
    {
        $client = self::createClient();
        HelpPages::root();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, '/admin/help-article/new');
        $client->submit($crawler->filter('form[name="Page"]')->form([
            'Page[helpKey]' => 'import.unknown_icon',
            'Page[icon]' => 'tabler:does-not-exist-anywhere',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="Page"]', 'The icon “tabler:does-not-exist-anywhere” does not exist.');

        $crawler = $client->request(Request::METHOD_GET, '/admin/help-article/new');
        $client->submit($crawler->filter('form[name="Page"]')->form([
            'Page[helpKey]' => 'import.bad_format',
            'Page[icon]' => 'upload',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="Page"]', 'Enter an icon such as tabler:upload.');

        $crawler = $client->request(Request::METHOD_GET, '/admin/help-article/new');
        $client->submit($crawler->filter('form[name="Page"]')->form([
            'Page[helpKey]' => 'import.with_icon',
            'Page[icon]' => ' tabler:file-import ',
        ]));
        self::assertResponseRedirects();

        $page = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Page::class)->findOneBy(['helpKey' => 'import.with_icon']);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame('tabler:file-import', $page->getIcon());
    }
}
