<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Domain\Enum\FeedbackStatus;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

    public function testDashboardBadgesCountNormalOpenFeedbackOnly(): void
    {
        $client = $this->loggedInAdmin();
        $this->feedback('Open normal feedback', FeedbackStatus::NEW, false);
        $this->feedback('Open quarantined spam one', FeedbackStatus::NEW, true);
        $this->feedback('Open quarantined spam two', FeedbackStatus::NEW, true);

        $crawler = $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();

        $menuBadge = $crawler->filter('.main-sidebar a[href*="/admin/feedback"] .menu-item-badge, #main-menu a[href*="/admin/feedback"] .badge');
        self::assertGreaterThan(0, $menuBadge->count(), 'the feedback menu item shows a badge');
        self::assertSame('1', trim($menuBadge->first()->text()));
        self::assertStringNotContainsString('quarantined spam', $crawler->filter('body')->text(), 'no content on the dashboard');
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
