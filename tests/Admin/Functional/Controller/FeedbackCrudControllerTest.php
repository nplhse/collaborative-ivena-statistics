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
