<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Integration\Infrastructure\EventSubscriber;

use App\Feedback\Application\Event\FeedbackReceived;
use App\Feedback\Application\RecordFeedbackHandler;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Address;

final class FeedbackReceivedNotificationSubscriberTest extends DatabaseKernelTestCase
{
    public function testFeedbackNotifiesEachFeedbackRecipientOnce(): void
    {
        $recipient = UserFactory::new()->asAdmin()->create(['email' => 'admin-feedback@example.test']);
        $systemRecipient = UserFactory::new()->asAdmin()->create();
        $preferences = new \App\Tests\Support\Notification\NotificationPreferenceTestState(self::getContainer()->get(Connection::class));
        $preferences->switchOff($systemRecipient, 'admin.feedback_received', 'in_app');
        $preferences->switchOff($systemRecipient, 'admin.feedback_received', 'email');
        $sender = UserFactory::createOne(['username' => 'feedback-sender', 'email' => 'feedback-sender@example.test']);

        $this->record($sender, FeedbackCategory::BUG, "The chart is broken.\nSee https://spam.example.test/x for details.");
        $feedbackId = (int) $this->connection()->fetchOne('SELECT id FROM feedback');
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new FeedbackReceived($feedbackId));

        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM notification WHERE recipient_id = ?', [$recipient->getId()]);
        self::assertCount(1, $rows, 'the same feedback notifies every recipient once');
        self::assertSame('admin.feedback_received', $rows[0]['type']);
        self::assertSame('role', $rows[0]['access_scope']);
        self::assertSame('ROLE_ADMIN', $rows[0]['required_role']);
        self::assertSame('app_admin_dashboard_feedback_detail', $rows[0]['action_route']);
        self::assertSame(['entityId' => $feedbackId], json_decode((string) $rows[0]['action_parameters'], true));
        self::assertSame(
            ['feedbackId' => $feedbackId, 'category' => 'bug', 'sender' => 'user', 'username' => 'feedback-sender'],
            json_decode((string) $rows[0]['parameters'], true),
            'the message is never stored in the notification',
        );
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$systemRecipient->getId()]));

        self::assertEmailCount(1);
        $email = $this->emailTo('admin-feedback@example.test');
        self::assertSame('New feedback: bug report', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('feedback-sender', $html);
        self::assertStringContainsString('The chart is broken.', $html);
        self::assertStringContainsString('/admin/feedback/'.$feedbackId, $html);
        self::assertStringNotContainsString('spam.example.test', $html, 'links are removed from the preview');
        self::assertStringNotContainsString('feedback-sender@example.test', $html);
        self::assertStringNotContainsString('Mozilla/5.0', $html, 'the user agent stays in the admin area');
        self::assertStringNotContainsString('1280x800', $html, 'the context stays in the admin area');
    }

    public function testGuestFeedbackNamesNoSender(): void
    {
        $recipient = UserFactory::new()->asAdmin()->create(['email' => 'admin-guest@example.test']);

        $this->record(null, FeedbackCategory::QUESTION, 'How do I import?');

        $parameters = json_decode((string) $this->connection()->fetchOne('SELECT parameters FROM notification WHERE recipient_id = ?', [$recipient->getId()]), true);
        self::assertIsArray($parameters);
        self::assertSame(['guest', ''], [$parameters['sender'], $parameters['username']]);

        $html = (string) $this->emailTo('admin-guest@example.test')->getHtmlBody();
        self::assertStringContainsString('Guest', $html);
        self::assertStringNotContainsString('guest@example.test', $html);
    }

    public function testMailFallsBackToTheGenericEmailWhenTheFeedbackIsGone(): void
    {
        $recipient = UserFactory::new()->asAdmin()->create(['email' => 'admin-gone@example.test']);

        self::getContainer()->get(NotificationPublisherInterface::class)->publish(new NotificationRequest(
            type: 'admin.feedback_received',
            recipientUserIds: [(int) $recipient->getId()],
            deduplicationKey: 'admin.feedback_received:999999',
            parameters: ['feedbackId' => 999999, 'category' => 'other', 'sender' => 'guest', 'username' => ''],
        ));

        $html = (string) $this->emailTo('admin-gone@example.test')->getHtmlBody();
        self::assertStringContainsString('A guest sent feedback.', $html, 'the generic notification email is sent');
    }

    public function testNoRecipientsOrUnknownFeedbackMeansNoNotification(): void
    {
        UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $this->record(null, FeedbackCategory::OTHER, 'Hello');
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new FeedbackReceived(999999));

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification'));
        self::assertEmailCount(0);
    }

    private function record(?User $sender, FeedbackCategory $category, string $message): void
    {
        self::getContainer()->get(RecordFeedbackHandler::class)->execute(
            category: $category,
            message: $message,
            guestEmail: $sender instanceof User ? null : 'guest@example.test',
            submittedBy: $sender,
            pageUrl: 'https://app.example.test/statistics?year=2026',
            routeName: 'app_statistics',
            context: ['viewport' => '1280x800'],
            userAgent: 'Mozilla/5.0 (Test)',
            appVersion: '1.2.3',
        );
    }

    private function emailTo(string $address): TemplatedEmail
    {
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof TemplatedEmail && \in_array($address, array_map(static fn (Address $to): string => $to->getAddress(), $message->getTo()), true)) {
                return $message;
            }
        }

        self::fail(\sprintf('No email to %s.', $address));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
