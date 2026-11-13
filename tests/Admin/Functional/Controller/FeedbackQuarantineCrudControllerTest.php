<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Domain\Enum\FeedbackStatus;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class FeedbackQuarantineCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testOnlyAdminsCanOpenTheQuarantine(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->create());

        $client->request(Request::METHOD_GET, '/admin/feedback-quarantine');

        self::assertResponseStatusCodeSame(403);
    }

    public function testQuarantineListsOnlyQuarantinedEntriesWithReasons(): void
    {
        [$client] = $this->loggedInAdmin();
        $this->feedback('Normal feedback text', quarantined: false);
        $this->feedback('Quarantined spam text', quarantined: true);

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback-quarantine');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Feedback quarantine — 1 entry', $text);
        self::assertStringContainsString('Quarantined spam text', $text);
        self::assertStringContainsString('missing_page_context', $text);
        self::assertStringNotContainsString('Normal feedback text', $text);
    }

    public function testQuarantinedContentIsRenderedAsPlainText(): void
    {
        [$client] = $this->loggedInAdmin();
        $id = $this->feedback('<img src="https://tracker.example.test/p.gif"><script>alert(1)</script> visit https://spam.example.test', quarantined: true);

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback-quarantine/'.$id);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<img src="https://tracker.example.test', $html, 'no remote image is loaded');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;img src=&quot;https://tracker.example.test/p.gif&quot;&gt;', $html);
        self::assertCount(0, $crawler->filter('a[href*="spam.example.test"]'), 'links stay text');
        self::assertCount(1, $crawler->filter('pre.ea-plain-text-pre'));

        $index = $client->request(Request::METHOD_GET, '/admin/feedback-quarantine');
        self::assertCount(0, $index->filter('table img'), 'the preview is escaped');
        self::assertStringContainsString('&lt;img src=&quot;', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('spam.example.test', $index->filter('table')->text(), 'the preview drops links');
    }

    public function testReleaseMovesTheEntryBackWithoutNotification(): void
    {
        [$client, $admin] = $this->loggedInAdmin();
        $id = $this->feedback('Legit question flagged by mistake', quarantined: true);

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback-quarantine/'.$id);
        $form = $crawler->filter(\sprintf('form[action*="/admin/feedback-quarantine/%d/release"]', $id));
        self::assertCount(1, $form, 'release is a POST form');
        $client->request(Request::METHOD_POST, (string) $form->attr('action'));

        self::assertResponseRedirects();
        $feedback = $this->reload($id);
        self::assertFalse($feedback->isQuarantined());
        self::assertSame([], $feedback->getClassificationReasons());
        self::assertSame($admin->getId(), $feedback->getClassifiedBy()?->getId());
        self::assertSame(FeedbackStatus::NEW, $feedback->getStatus());
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM notification'), 'release sends no notification');
    }

    public function testReleaseWithoutValidTokenChangesNothing(): void
    {
        [$client] = $this->loggedInAdmin();
        $id = $this->feedback('Spam', quarantined: true);

        $client->request(Request::METHOD_POST, \sprintf('/admin/feedback-quarantine/%d/release?token=forged', $id));

        self::assertResponseRedirects();
        self::assertTrue($this->reload($id)->isQuarantined());
    }

    public function testReleaseIsNotReachableByGet(): void
    {
        [$client] = $this->loggedInAdmin();
        $id = $this->feedback('Spam', quarantined: true);

        $client->request(Request::METHOD_GET, \sprintf('/admin/feedback-quarantine/%d/release', $id));

        self::assertTrue($this->reload($id)->isQuarantined());
    }

    public function testBatchReleaseAndBatchDelete(): void
    {
        [$client] = $this->loggedInAdmin();
        $releaseOne = $this->feedback('Release one', quarantined: true);
        $releaseTwo = $this->feedback('Release two', quarantined: true);
        $deleteOne = $this->feedback('Delete one', quarantined: true);
        $deleteTwo = $this->feedback('Delete two', quarantined: true);
        $normal = $this->feedback('Normal entry', quarantined: false);

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback-quarantine');
        $releaseToken = (string) $crawler->filter('[data-action-name="batchRelease"]')->attr('data-action-csrf-token');
        $deleteToken = (string) $crawler->filter('[data-action-name="batchDelete"]')->attr('data-action-csrf-token');

        $client->request(Request::METHOD_POST, '/admin/feedback-quarantine/batch-release', [
            'batchActionName' => 'batchRelease',
            'batchActionEntityIds' => [(string) $releaseOne, (string) $releaseTwo],
            'batchActionCsrfToken' => $releaseToken,
            'entityFqcn' => Feedback::class,
        ]);
        self::assertResponseRedirects();

        $client->request(Request::METHOD_POST, '/admin/feedback-quarantine/batch-delete', [
            'batchActionName' => 'batchDelete',
            'batchActionEntityIds' => [(string) $deleteOne, (string) $deleteTwo, (string) $normal],
            'batchActionCsrfToken' => $deleteToken,
            'entityFqcn' => Feedback::class,
        ]);
        self::assertResponseRedirects();

        $repository = self::getContainer()->get(FeedbackRepository::class);
        self::assertFalse($this->reload($releaseOne)->isQuarantined());
        self::assertFalse($this->reload($releaseTwo)->isQuarantined());
        self::assertNull($repository->find($deleteOne));
        self::assertNull($repository->find($deleteTwo));
        self::assertNotNull($repository->find($normal), 'normal feedback is never deleted from the quarantine');
    }

    public function testNormalViewSendsQuarantinedEntriesToTheQuarantine(): void
    {
        [$client] = $this->loggedInAdmin();
        $id = $this->feedback('Quarantined spam text', quarantined: true);

        $client->request(Request::METHOD_GET, '/admin/feedback/'.$id);

        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/feedback-quarantine/'.$id, (string) $client->getResponse()->headers->get('Location'));
    }

    public function testMissedSpamCanBeMovedIntoQuarantine(): void
    {
        [$client, $admin] = $this->loggedInAdmin();
        $id = $this->feedback('Spam that slipped through', quarantined: false);

        $crawler = $client->request(Request::METHOD_GET, '/admin/feedback/'.$id);
        $form = $crawler->filter(\sprintf('form[action*="/admin/feedback/%d/move-to-quarantine"]', $id));
        self::assertCount(1, $form);
        $client->request(Request::METHOD_POST, (string) $form->attr('action'));

        self::assertResponseRedirects();
        $feedback = $this->reload($id);
        self::assertTrue($feedback->isQuarantined());
        self::assertSame(['manual'], $feedback->getClassificationReasons());
        self::assertNull($feedback->getSpamScore());
        self::assertSame($admin->getId(), $feedback->getClassifiedBy()?->getId());
        self::assertNotNull($feedback->getQuarantineReportedAt(), 'a manual quarantine is not reported in the summary');
    }

    /** @return array{KernelBrowser, User} */
    private function loggedInAdmin(): array
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $client->loginUser($admin);

        return [$client, $admin];
    }

    private function feedback(string $message, bool $quarantined): int
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage($message)
            ->setGuestEmail('guest@example.test')
            ->setPageUrl('https://example.test/');
        if ($quarantined) {
            $feedback->quarantine(['missing_page_context', 'token_only_message'], 7, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();

        return (int) $feedback->getId();
    }

    private function reload(int $id): Feedback
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $feedback = $entityManager->find(Feedback::class, $id);
        self::assertInstanceOf(Feedback::class, $feedback);

        return $feedback;
    }
}
