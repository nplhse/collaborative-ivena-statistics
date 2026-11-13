<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Domain\Enum\FeedbackStatus;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class FeedbackCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testListAndCountsLeaveQuarantinedFeedbackOut(): void
    {
        $client = $this->loggedInAdmin();
        $this->feedback('Open normal feedback', FeedbackStatus::NEW, false);
        $this->feedback('Done normal feedback', FeedbackStatus::DONE, false);
        $this->feedback('Open quarantined spam', FeedbackStatus::NEW, true);
        $this->feedback('Done quarantined spam', FeedbackStatus::DONE, true);

        $repository = self::getContainer()->get(FeedbackRepository::class);
        self::assertSame(1, $repository->countOpen(), 'only the open normal entry counts as open');
        self::assertSame(2, $repository->countQuarantined(), 'quarantine counts regardless of the status');

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback');
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Feedback — 1 open', $text);
        self::assertStringContainsString('Open normal feedback', $text);
        self::assertStringContainsString('Done normal feedback', $text);
        self::assertStringNotContainsString('quarantined spam', $text);
    }

    public function testFeedbackMenuGroupBadgeCountsNormalOpenFeedbackOnly(): void
    {
        $client = $this->loggedInAdmin();
        $this->feedback('Open normal feedback', FeedbackStatus::NEW, false);
        $this->feedback('Done normal feedback', FeedbackStatus::DONE, false);
        $this->feedback('Open quarantined spam one', FeedbackStatus::NEW, true);
        $this->feedback('Open quarantined spam two', FeedbackStatus::NEW, true);

        $crawler = $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();

        $group = $this->feedbackMenuGroup($crawler);
        $groupLink = $group->children('.ea-sidebar-item-link');
        $groupBadge = $groupLink->filter('.ea-sidebar-badge');
        self::assertCount(1, $groupBadge, 'the collapsed feedback group shows one badge');
        self::assertSame('1', trim($groupBadge->text()), 'one open normal entry; done and quarantined entries do not count');
        self::assertStringContainsString('ea-sidebar-link-summary-badge', (string) $groupLink->attr('class'), 'the group badge is hidden by CSS once the group is open');

        $subItems = $group->filter('.ea-sidebar-submenu-items > li.ea-sidebar-item');
        self::assertSame(
            ['Feedback Inbox', 'Quarantine'],
            $subItems->each(static fn (Crawler $item): string => trim($item->filter('.ea-sidebar-item-label')->text())),
        );
        $inboxBadge = $subItems->eq(0)->filter('.ea-sidebar-badge');
        self::assertCount(1, $inboxBadge, 'the inbox entry shows its count');
        self::assertSame('1', trim($inboxBadge->text()), 'same count as the group badge');
        self::assertStringContainsString('badge-info', (string) $inboxBadge->attr('class'));
        $quarantineBadge = $subItems->eq(1)->filter('.ea-sidebar-badge');
        self::assertCount(1, $quarantineBadge, 'the quarantine entry shows its count');
        self::assertSame('2', trim($quarantineBadge->text()), 'two quarantined entries, regardless of their status');
        self::assertStringContainsString('badge-warning', (string) $quarantineBadge->attr('class'), 'the quarantine count differs in colour from the group badge');
        self::assertStringNotContainsString('quarantined spam', $crawler->filter('body')->text(), 'no content on the dashboard');
    }

    public function testFeedbackMenuGroupHasNoBadgeWithoutOpenFeedback(): void
    {
        $client = $this->loggedInAdmin();
        $this->feedback('Done normal feedback', FeedbackStatus::DONE, false);
        $this->feedback('Open quarantined spam', FeedbackStatus::NEW, true);

        $crawler = $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();

        $group = $this->feedbackMenuGroup($crawler);
        self::assertCount(0, $group->children('.ea-sidebar-item-link')->filter('.ea-sidebar-badge'), 'no group badge without open normal feedback');
        $subItems = $group->filter('.ea-sidebar-submenu-items > li.ea-sidebar-item');
        self::assertCount(0, $subItems->eq(0)->filter('.ea-sidebar-badge'), 'no inbox badge without open normal feedback');
        self::assertSame('1', trim($subItems->eq(1)->filter('.ea-sidebar-badge')->text()), 'the quarantine count stays visible');
    }

    public function testFeedbackMenuHasNoQuarantineBadgeWithoutQuarantinedFeedback(): void
    {
        $client = $this->loggedInAdmin();
        $this->feedback('Open normal feedback', FeedbackStatus::NEW, false);

        $crawler = $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();

        $subItems = $this->feedbackMenuGroup($crawler)->filter('.ea-sidebar-submenu-items > li.ea-sidebar-item');
        self::assertCount(0, $subItems->eq(1)->filter('.ea-sidebar-badge'), 'no quarantine badge without quarantined feedback');
    }

    #[DataProvider('feedbackSections')]
    public function testFeedbackMenuHighlightsTheCurrentSection(string $path, string $activeLabel): void
    {
        $client = $this->loggedInAdmin();

        $crawler = $client->request(Request::METHOD_GET, $path);
        self::assertResponseIsSuccessful();

        $group = $this->feedbackMenuGroup($crawler);
        self::assertStringContainsString('is-expanded', (string) $group->attr('class'), 'the feedback group is expanded');

        $active = $group->filter('.ea-sidebar-submenu-items > li.ea-sidebar-item.is-active');
        self::assertCount(1, $active);
        self::assertSame($activeLabel, trim($active->filter('.ea-sidebar-item-label')->text()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function feedbackSections(): iterable
    {
        yield 'inbox' => ['/admin/feedback', 'Feedback Inbox'];
        yield 'quarantine' => ['/admin/feedback-quarantine', 'Quarantine'];
    }

    public function testListAndDetailEscapeTheMessageAndPath(): void
    {
        $client = $this->loggedInAdmin();
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('<img src="https://tracker.example.test/p.gif"> broken chart')
            ->setPageUrl('https://example.test/<b>bold</b>')
            ->setRouteName('<i>route</i>');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table img'));
        self::assertCount(0, $crawler->filter('table i:not([class])'), 'the route is escaped');
        self::assertStringContainsString('&lt;img src=&quot;https://tracker.example.test/p.gif&quot;&gt; broken chart', (string) $client->getResponse()->getContent());

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback/'.$feedback->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.content-body b, main b'), 'the path is escaped');
    }

    public function testDetailShowsTheContextAsEscapedJson(): void
    {
        $client = $this->loggedInAdmin();
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('Chart is empty')
            ->setPageUrl('https://example.test/statistics')
            ->setContext([
                'route_params' => ['id' => 7],
                'extra' => ['note' => '<img src="https://tracker.example.test/c.gif">'],
            ]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback/'.$feedback->getId());
        self::assertResponseIsSuccessful();

        $pre = $crawler->filter('pre.ea-json-pre');
        self::assertCount(1, $pre);
        self::assertStringContainsString('"route_params"', $pre->text());
        self::assertStringContainsString('"id": 7', $pre->text());
        self::assertCount(0, $pre->filter('img'), 'the context is escaped');
        self::assertStringContainsString('&lt;img src=\&quot;https://tracker.example.test/c.gif\&quot;&gt;', (string) $client->getResponse()->getContent());
    }

    public function testDetailShowsADashWithoutContext(): void
    {
        $client = $this->loggedInAdmin();
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('No context here')
            ->setPageUrl('https://example.test/');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback/'.$feedback->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('pre.ea-json-pre'));
    }

    private function loggedInAdmin(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }

    private function feedbackMenuGroup(Crawler $crawler): Crawler
    {
        $group = $crawler->filter('li.ea-sidebar-item.has-submenu')->reduce(
            static fn (Crawler $item): bool => 'Feedback' === trim($item->filter('.ea-sidebar-item-link > .ea-sidebar-item-label')->text('')),
        );
        self::assertCount(1, $group, 'the sidebar has one feedback group');

        return $group;
    }

    private function feedback(string $message, FeedbackStatus $status, bool $quarantined): void
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage($message)
            ->setPageUrl('https://example.test/')
            ->setStatus($status);
        if ($quarantined) {
            $feedback->quarantine(['missing_page_context', 'contains_url'], 8, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();
    }
}
