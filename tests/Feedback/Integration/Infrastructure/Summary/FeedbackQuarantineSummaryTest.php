<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Integration\Infrastructure\Summary;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Infrastructure\Notification\AdminFeedbackQuarantineSummaryNotification;
use App\Feedback\Infrastructure\Summary\FeedbackQuarantineSummary;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\NotificationPreferenceTestState;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;

final class FeedbackQuarantineSummaryTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private const string TYPE = AdminFeedbackQuarantineSummaryNotification::TYPE;

    public function testNothingNewSendsNothing(): void
    {
        $this->optedInAdmin('admin-empty@example.test');
        $this->feedback('Normal feedback', automatic: false, manual: false);
        $this->feedback('Known spam', automatic: false, manual: true);

        self::assertSame(0, $this->summary()->countPending());
        self::assertSame(0, $this->summary()->send());
        self::assertSame(0, $this->notificationCount());
        self::assertEmailCount(0);
    }

    public function testReportsNewAutomaticQuarantinesOnceToOptedInAdmins(): void
    {
        self::mockTime('2026-11-16 08:00:00');
        $optedIn = $this->optedInAdmin('admin-summary@example.test');
        $notOptedIn = UserFactory::new()->asAdmin()->create(['email' => 'admin-quiet@example.test']);
        $first = $this->feedback('Spam one https://spam.example.test', automatic: true, manual: false);
        $second = $this->feedback('Spam two', automatic: true, manual: false);
        $third = $this->feedback('Spam three', automatic: true, manual: false);
        $manual = $this->feedback('Spam moved by a reviewer', automatic: false, manual: true);
        $this->feedback('Normal feedback', automatic: false, manual: false);

        self::assertSame(3, $this->summary()->countPending());
        self::assertSame(3, $this->summary()->send());

        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM notification WHERE type = ?', [self::TYPE]);
        self::assertCount(1, $rows, 'only the admin who switched the type on gets it');
        self::assertSame($optedIn->getId(), (int) $rows[0]['recipient_id']);
        self::assertSame(['count' => 3, 'total' => 4], json_decode((string) $rows[0]['parameters'], true), 'counts only, no content');
        self::assertSame(FeedbackQuarantineSummary::QUARANTINE_ROUTE, $rows[0]['action_route']);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$notOptedIn->getId()]));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $html = (string) $email->getHtmlBody();
        self::assertStringNotContainsString('spam.example.test', $html);
        self::assertStringNotContainsString('Spam one', $html);

        $reportedAt = $this->connection()->fetchAllKeyValue('SELECT id, quarantine_reported_at FROM feedback ORDER BY id');
        foreach ([$first, $second, $third] as $id) {
            self::assertSame('2026-11-16 08:00:00', $reportedAt[$id]);
        }
        self::assertSame('2026-11-10 09:00:00', $reportedAt[$manual], 'a reviewer\'s quarantine keeps its own time');

        self::mockTime('2026-11-23 08:00:00');
        self::assertSame(0, $this->summary()->send(), 'a later run never reports the same entries again');
        self::assertSame(1, $this->notificationCount());
    }

    public function testOnlyEntriesAfterThePreviousSummaryAreReported(): void
    {
        $this->optedInAdmin('admin-weekly@example.test');
        $this->feedback('Spam week one', automatic: true, manual: false);
        self::assertSame(1, $this->summary()->send());

        $this->feedback('Spam week two a', automatic: true, manual: false);
        $this->feedback('Spam week two b', automatic: true, manual: false);
        self::assertSame(2, $this->summary()->send());

        $parameters = $this->connection()->fetchFirstColumn('SELECT parameters FROM notification WHERE type = ? ORDER BY id', [self::TYPE]);
        self::assertSame(['count' => 1, 'total' => 1], json_decode((string) $parameters[0], true));
        self::assertSame(['count' => 2, 'total' => 3], json_decode((string) $parameters[1], true));
    }

    public function testReleasedEntriesAreNotReported(): void
    {
        $admin = $this->optedInAdmin('admin-release@example.test');
        $id = $this->feedback('Legit after all', automatic: true, manual: false);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $feedback = $entityManager->find(Feedback::class, $id);
        self::assertInstanceOf(Feedback::class, $feedback);
        $feedback->release($admin, new \DateTimeImmutable('2026-11-12 10:00:00'));
        $entityManager->flush();

        self::assertSame(0, $this->summary()->send());
    }

    public function testAFailedRunMarksNothingAndTheNextRunReportsTheEntries(): void
    {
        $this->optedInAdmin('admin-retry@example.test');
        $this->feedback('Spam during an outage', automatic: true, manual: false);

        $failing = new FeedbackQuarantineSummary(
            $this->connection(),
            self::getContainer()->get(AdminNotificationRecipientResolverInterface::class),
            new class implements NotificationPublisherInterface {
                public function publish(NotificationRequest $request): void
                {
                    throw new \RuntimeException('queue unavailable');
                }
            },
            self::getContainer()->get(ClockInterface::class),
        );

        try {
            $failing->send();
            self::fail('the failure must surface so the job is retried');
        } catch (\RuntimeException $e) {
            self::assertSame('queue unavailable', $e->getMessage());
        }

        self::assertSame(1, $this->summary()->countPending(), 'nothing was marked');
        self::assertSame(1, $this->summary()->send());
        self::assertSame(1, $this->notificationCount());
    }

    private function summary(): FeedbackQuarantineSummary
    {
        return self::getContainer()->get(FeedbackQuarantineSummary::class);
    }

    private function optedInAdmin(string $email): User
    {
        $admin = UserFactory::new()->asAdmin()->create(['email' => $email]);
        $preferences = new NotificationPreferenceTestState($this->connection());
        $preferences->switchOn($admin, self::TYPE, 'in_app');
        $preferences->switchOn($admin, self::TYPE, 'email');

        return $admin;
    }

    private function feedback(string $message, bool $automatic, bool $manual): int
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage($message)
            ->setPageUrl('https://example.test/');
        if ($automatic) {
            $feedback->quarantine(['missing_page_context', 'token_only_message'], 7, null, new \DateTimeImmutable('2026-11-10 08:00:00'));
        } elseif ($manual) {
            $feedback->quarantine(['manual'], null, UserFactory::new()->asAdmin()->create(), new \DateTimeImmutable('2026-11-10 09:00:00'));
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($feedback);
        $entityManager->flush();

        return (int) $feedback->getId();
    }

    private function notificationCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE type = ?', [self::TYPE]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
