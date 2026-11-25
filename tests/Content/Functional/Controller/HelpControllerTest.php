<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Entity\HelpFeedback;
use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Tests\Support\Content\HelpPages;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Help section (#596): start page, articles with outline, help navigation and neighbours, search, helpfulness, and
 * how the help appears in the page tree, the sitemap and the header.
 *
 * Fixture: Help > Importing (> Export, > Rejected rows), Statistics; one ordinary page "Guide".
 */
#[ResetDatabase]
final class HelpControllerTest extends WebTestCase
{
    use Factories;

    private Page $importing;
    private Page $rejects;

    public function testArticleShowsOutlineHelpNavigationAndNeighbours(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/help/importing/rejected-rows');

        self::assertResponseIsSuccessful();
        self::assertSame(['Why rows are rejected', 'Fixing them'], $crawler->filter('[data-testid="page-toc"] a')->each(static fn (Crawler $link): string => trim($link->text())), 'outline without show-TOC flag');
        self::assertCount(1, $crawler->filter('[data-testid="help-search"]'));
        self::assertSame(['Importing', 'Export', 'Rejected rows', 'Statistics'], $this->navigationTitles($crawler, '[data-testid="help-navigation"]'), 'the topics without the start page');
        self::assertSame('Export', trim($crawler->filter('[data-testid="help-article-previous"] .fw-medium')->text()));
        self::assertSame('Statistics', trim($crawler->filter('[data-testid="help-article-next"] .fw-medium')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="help-feedback-frame"]'), 'visitors cannot rate');
        self::assertSame(['Guide', 'Help'], $this->navigationTitles($crawler, '[data-testid="help-page-navigation"]'), 'the other pages stay reachable');
        self::assertStringNotContainsString('blog.sidebar.latest_posts', $crawler->html());
    }

    public function testStartPageHasTopicsAndOnlyItsOwnSearch(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/help');

        self::assertResponseIsSuccessful();
        self::assertSame(['Importing', 'Statistics'], $crawler->filter('[data-testid="help-topic"] .card-title')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['Export', 'Rejected rows'], $crawler->filter('[data-testid="help-topic"]')->first()->filter('li')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertCount(1, $crawler->filter('[data-testid="help-search-form"]'), 'the search block, not a second one in the sidebar');
        self::assertCount(0, $crawler->filter('[data-testid="help-article-pagination"]'));
    }

    public function testTopicPageListsItsArticlesAsCardsAndTheStartPageShowsTopicSummaries(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/help/importing');

        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('[data-testid="page-child-card"]');
        self::assertSame(['Export', 'Rejected rows'], $cards->filter('.card-title')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['/help/importing/export', '/help/importing/rejected-rows'], $cards->each(static fn (Crawler $card): string => (string) $card->attr('href')));
        self::assertStringContainsString('Create the CSV file in IVENA, not in a spreadsheet.', $cards->first()->text());
        self::assertStringContainsString('1 minute read', $cards->first()->text());
        self::assertCount(0, $crawler->filter('[data-testid="page-child-cards"] .badge'), 'not numbered');
        self::assertCount(1, $cards->first()->filter('[data-testid="page-child-card-icon"] svg'), 'the export article has an icon');
        self::assertCount(0, $cards->last()->filter('[data-testid="page-child-card-icon"]'), 'rejected rows has none');
        self::assertSame('How IVENA exports get into the application.', $crawler->filter('meta[name="description"]')->attr('content'));

        $crawler = $client->request(Request::METHOD_GET, '/help/importing/export');
        self::assertCount(0, $crawler->filter('[data-testid="page-child-cards"]'), 'articles without subpages show no cards');

        $crawler = $client->request(Request::METHOD_GET, '/help');
        self::assertStringContainsString('How IVENA exports get into the application.', $crawler->filter('[data-testid="help-topic"]')->first()->text());
        self::assertCount(1, $crawler->filter('[data-testid="help-topic"]')->first()->filter('[data-testid="help-topic-icon"] svg'), 'the importing topic has an icon');
        self::assertCount(0, $crawler->filter('[data-testid="help-topic"]')->last()->filter('[data-testid="help-topic-icon"]'), 'topics without icon show none');
        self::assertCount(0, $crawler->filter('[data-testid="page-child-cards"]'), 'the start page has its topic overview');
    }

    public function testLearnMoreBlockListsCuratedLinksTheViewerCanOpen(): void
    {
        $client = self::createClient();
        $this->fixture();
        HelpPages::article($this->importing, 'Checklist', '/help/importing/checklist', '', 2, ['content' => [
            ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Before the import.</p>']],
            ['type' => 'read_more', 'enabled' => true, 'data' => ['title' => '', 'items' => [
                ['linkUrl' => '/help/importing/export', 'linkLabel' => ''],
                ['linkUrl' => '/help/statistics#charts', 'linkLabel' => 'Reading the charts'],
                ['linkUrl' => '/help/members', 'linkLabel' => ''],
                ['linkUrl' => 'https://example.org/ivena', 'linkLabel' => 'IVENA manual'],
                ['linkUrl' => 'https://example.org/unnamed', 'linkLabel' => ''],
            ]]],
        ]]);

        $crawler = $client->request(Request::METHOD_GET, '/help/importing/checklist');

        self::assertResponseIsSuccessful();
        self::assertSame('Learn more', trim($crawler->filter('[data-testid="page-read-more"] h2')->text()));
        $links = $crawler->filter('[data-testid="page-read-more-link"] a');
        self::assertSame(['Export', 'Reading the charts', 'IVENA manual'], $links->each(static fn (Crawler $link): string => trim($link->text())),
            'page title by default, the link text when given; the members-only page and the unnamed address drop out');
        self::assertSame(['/help/importing/export', '/help/statistics#charts', 'https://example.org/ivena'], $links->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertStringContainsString('Create the CSV file in IVENA, not in a spreadsheet.', $crawler->filter('[data-testid="page-read-more-link"]')->first()->text(), 'with the short description');
    }

    public function testSearchShowsTheSummaryInsteadOfTheExcerpt(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/help/search?q=quarter');

        self::assertResponseIsSuccessful();
        self::assertSame('Create the CSV file in IVENA, not in a spreadsheet.', trim($crawler->filter('[data-testid="help-search-hit"] .help-search-summary')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="help-search-hit"] .help-search-excerpt'));
    }

    public function testOtherPagesAndTheSitemapLeaveOutTheArticles(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/guide');
        self::assertResponseIsSuccessful();
        self::assertSame(['Guide', 'Help'], $this->navigationTitles($crawler, '[data-testid="page-navigation"]'));

        $sitemap = $client->request(Request::METHOD_GET, '/sitemap')->html();
        self::assertStringContainsString('Importing', $sitemap, 'main topics are listed');
        self::assertStringNotContainsString('Rejected rows', $sitemap);

        $xml = $client->request(Request::METHOD_GET, '/sitemap.xml');
        self::assertStringContainsString('/help/importing/rejected-rows', (string) $client->getResponse()->getContent(), 'search engines get every public article');
        unset($xml);
    }

    public function testHeaderLinksTheHelpInsteadOfTheFaq(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/guide');

        self::assertCount(1, $crawler->filter('header a[href="/help"]'));
        self::assertCount(1, $crawler->filter('footer a[href="/help"]'));
    }

    public function testSearchFindsVisibleArticles(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/help/search?q=plausib');

        self::assertResponseIsSuccessful();
        self::assertSame(['/help/importing/rejected-rows?q=plausib'], $crawler->filter('[data-testid="help-search-hit"]')->each(static fn (Crawler $hit): string => (string) $hit->attr('href')), 'the hit carries the search terms to the article');
        self::assertSame('help-search-hit-'.$this->rejects->getId(), $crawler->filter('[data-testid="help-search-hit"]')->attr('id'), 'the article links back to this anchor');
        self::assertSelectorTextContains('[data-testid="help-search-hit"]', 'Rejected rows');
        self::assertSame('plausib', $crawler->filter('[data-testid="help-search"] input[name="q"]')->attr('value'));

        $crawler = $client->request(Request::METHOD_GET, '/help/search?q=members');
        self::assertCount(0, $crawler->filter('[data-testid="help-search-hit"]'), 'articles for signed-in users stay hidden from visitors');

        $client->loginUser(UserFactory::createOne());
        $crawler = $client->request(Request::METHOD_GET, '/help/search?q=members');
        self::assertCount(1, $crawler->filter('[data-testid="help-search-hit"]'));
    }

    public function testArticleOpenedFromTheSearchShowsTheTermsAndLinksBackToTheHit(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/help/importing/rejected-rows?q='.rawurlencode(' plausib check '));

        self::assertResponseIsSuccessful();
        self::assertSame('plausib check', $crawler->filter('[data-controller="help-search-terms"]')->attr('data-help-search-terms-terms-value'));
        self::assertCount(1, $crawler->filter('[data-help-search-terms-target="content"]'));
        self::assertSelectorTextContains('[data-testid="help-search-terms-bar"]', 'plausib check');
        self::assertSame(
            '/help/search?q=plausib%20check#help-search-hit-'.$this->rejects->getId(),
            $crawler->filter('[data-testid="help-search-terms-back"]')->attr('href'),
        );
        self::assertSame('/help/importing/rejected-rows', $crawler->filter('[data-testid="help-search-terms-clear"]')->attr('href'), 'without JavaScript the link opens the article without terms');
    }

    public function testTermsBarOnlyAppearsOnHelpArticlesWithSearchTerms(): void
    {
        $client = self::createClient();
        $this->fixture();

        $crawler = $client->request(Request::METHOD_GET, '/help/importing/rejected-rows');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="help-search-terms-bar"], [data-controller="help-search-terms"]'), 'no search, no bar');

        $crawler = $client->request(Request::METHOD_GET, '/help/importing/rejected-rows?q=%20%20');
        self::assertCount(0, $crawler->filter('[data-testid="help-search-terms-bar"]'), 'blank terms');

        $crawler = $client->request(Request::METHOD_GET, '/guide?q=guide');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="help-search-terms-bar"]'), 'ordinary pages ignore the parameter');
    }

    public function testSearchWithoutHelpSectionIsNotFound(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/help/search?q=export');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSignedInUsersRateAndCommentAnArticle(): void
    {
        $client = self::createClient();
        $this->fixture();
        $user = UserFactory::createOne(['username' => 'rater']);
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, '/help/importing/rejected-rows');
        self::assertCount(1, $crawler->filter('[data-testid="help-feedback-frame"]'));

        $widget = $this->widget($client);
        $client->submit($widget->filter('[data-testid="help-feedback-yes"]')->form(), [], $this->frameHeaders());
        self::assertSame('thanks', $client->getCrawler()->filter('[data-testid="help-feedback"]')->attr('data-state'));
        self::assertSame([true], $this->answers());

        $client->submit($client->getCrawler()->filter('[data-testid="help-feedback-no"]')->form(), [], $this->frameHeaders());
        self::assertSame('ask_comment', $client->getCrawler()->filter('[data-testid="help-feedback"]')->attr('data-state'));
        self::assertSame([false], $this->answers(), 'answering again changes the answer');

        $client->submit($client->getCrawler()->filter('[data-testid="help-feedback-send"]')->form(['comment' => 'An example CSV row would help.']), [], $this->frameHeaders());
        self::assertSelectorExists('[data-testid="help-feedback-commented"]');

        $feedback = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Feedback::class)->findAll();
        self::assertCount(1, $feedback);
        self::assertSame('An example CSV row would help.', $feedback[0]->getMessage());
        self::assertSame(FeedbackCategory::IMPROVEMENT, $feedback[0]->getCategory());
        self::assertSame('http://localhost/help/importing/rejected-rows', $feedback[0]->getPageUrl());
        self::assertSame(['source' => 'help_article', 'help_article_id' => $this->rejects->getId(), 'help_article_title' => 'Rejected rows', 'locale' => 'en'], $feedback[0]->getContext()['extra'] ?? null);
    }

    public function testShortCommentsAreNotSentAndPlainFormsReturnToTheArticle(): void
    {
        $client = self::createClient();
        $this->fixture();
        $client->loginUser(UserFactory::createOne());
        $token = $this->widget($client)->filter('input[name="_token"]')->attr('value');

        $client->request(Request::METHOD_POST, '/help/feedback/'.$this->rejects->getId(), ['_token' => $token, 'comment' => 'no']);
        self::assertResponseRedirects('/help/importing/rejected-rows', 303);
        self::assertSame([], self::getContainer()->get(EntityManagerInterface::class)->getRepository(Feedback::class)->findAll());
    }

    public function testFeedbackIsRefusedForVisitorsOtherPagesAndWithoutToken(): void
    {
        $client = self::createClient();
        $this->fixture();

        $client->request(Request::METHOD_GET, '/help/feedback/'.$this->rejects->getId());
        self::assertResponseRedirects();

        $client->loginUser(UserFactory::createOne());
        $client->request(Request::METHOD_GET, '/help/feedback/'.$this->importing->getParent()?->getId());
        self::assertResponseStatusCodeSame(404, 'the start page is not an article');

        $client->request(Request::METHOD_POST, '/help/feedback/'.$this->rejects->getId(), ['_token' => 'wrong', 'helpful' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->answers());
    }

    private function fixture(): void
    {
        PageFactory::createOne(['title' => 'Guide', 'slug' => 'guide', 'path' => '/guide', 'status' => 'published']);
        $root = HelpPages::root();
        $this->importing = HelpPages::article($root, 'Importing', '/help/importing', '<p>About imports.</p>', 0, ['summary' => 'How IVENA exports get into the application.', 'icon' => 'tabler:file-import']);
        HelpPages::article($this->importing, 'Export', '/help/importing/export', '<p>Export by quarter.</p>', 0, ['summary' => 'Create the CSV file in IVENA, not in a spreadsheet.', 'icon' => 'tabler:file-export']);
        $this->rejects = HelpPages::article($this->importing, 'Rejected rows', '/help/importing/rejected-rows',
            '<h2>Why rows are rejected</h2><p>Rows that fail the plausibility checks.</p><h2>Fixing them</h2><p>Import again.</p>', 1, ['helpKey' => 'import.rejects']);
        HelpPages::article($root, 'Statistics', '/help/statistics', '<p>About statistics.</p>', 1);
        HelpPages::article($root, 'Members only', '/help/members', '<p>Members content.</p>', 2, ['visibility' => Page::VISIBILITY_AUTHENTICATED]);
    }

    private function widget(KernelBrowser $client): Crawler
    {
        $crawler = $client->request(Request::METHOD_GET, '/help/feedback/'.$this->rejects->getId(), [], [], $this->frameHeaders());
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-testid="help-feedback"]');
    }

    /**
     * @return array<string, string>
     */
    private function frameHeaders(): array
    {
        return ['HTTP_TURBO_FRAME' => 'help-feedback-'.$this->rejects->getId()];
    }

    /**
     * @return list<bool>
     */
    private function answers(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return array_map(static fn (HelpFeedback $feedback): bool => $feedback->isHelpful(), $entityManager->getRepository(HelpFeedback::class)->findAll());
    }

    /**
     * @return list<string>
     */
    private function navigationTitles(Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector.' nav a')->each(static fn (Crawler $link): string => trim($link->text()));
    }
}
