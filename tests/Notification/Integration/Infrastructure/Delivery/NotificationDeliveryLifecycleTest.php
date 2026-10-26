<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Delivery;

use App\Notification\Application\Event\NotificationDeliverySent;
use App\Notification\Application\Message\DeliverNotificationMessage;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryAnnouncer;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryQueue;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryStatusSubscriber;
use App\Notification\Infrastructure\Delivery\NotificationEmailFactory;
use App\Notification\Infrastructure\Delivery\PendingDeliveryResumer;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Messenger\RecordingMessageBus;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope as MailerEnvelope;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Delivery states after the job: failures from the mail transport or the job itself, and pending
 * deliveries picked up again by reconciliation (ADR 016).
 */
final class NotificationDeliveryLifecycleTest extends DatabaseKernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testFinalFailuresSetAReasonCodeAndRetriesChangeNothing(): void
    {
        $mail = $this->delivery('queued');
        $job = $this->delivery('pending');
        $sent = $this->delivery('sent');
        $subscriber = new NotificationDeliveryStatusSubscriber($this->connection, new MockClock('2026-10-23 08:00:00'), new NullLogger(), self::getContainer()->get(NotificationDeliveryAnnouncer::class));

        $subscriber->onMessageFailed($this->failed($this->emailMessage($mail), willRetry: true));
        self::assertSame(['queued', null], $this->state($mail), 'a retry follows');

        $subscriber->onMessageFailed($this->failed($this->emailMessage($mail)));
        $subscriber->onMessageFailed($this->failed(new DeliverNotificationMessage($job)));
        $subscriber->onMessageFailed($this->failed(new DeliverNotificationMessage($sent)));
        $subscriber->onMessageFailed($this->failed(new SendEmailMessage(new Email())));
        $subscriber->onMessageFailed($this->failed(new SendEmailMessage(new RawMessage('raw'))));
        $subscriber->onMessageFailed($this->failed(new \stdClass()));

        self::assertSame(['failed', 'transport_failed'], $this->state($mail));
        self::assertSame(['failed', 'delivery_job_failed'], $this->state($job));
        self::assertSame(['sent', null], $this->state($sent), 'a sent delivery stays sent');

        // A delivery that is gone (e.g. deleted with its instance) announces nothing.
        self::getContainer()->get(NotificationDeliveryAnnouncer::class)->announceFailed(999_999, \App\Notification\Domain\Enum\NotificationDeliveryFailureReason::DeliveryJobFailed);
    }

    /**
     * Stored pending deliveries are resumed whatever their age (the 24-hour window only limits the rebuilding of
     * business events); younger than 15 minutes they are left to the running job, and only pending ones count.
     */
    public function testAnAcceptedEmailIsSentAndAnnouncedOnce(): void
    {
        $mail = $this->delivery('queued');
        $announced = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NotificationDeliverySent::class,
            static function (NotificationDeliverySent $event) use (&$announced): void {
                $announced[] = $event;
            },
        );
        $subscriber = self::getContainer()->get(NotificationDeliveryStatusSubscriber::class);

        $subscriber->onSent($this->sentEmail($mail));
        $subscriber->onSent($this->sentEmail($mail));

        self::assertSame(['sent', null], $this->state($mail));
        self::assertCount(1, $announced, 'a delivery that is already sent is not announced again by the mail event');
        self::assertSame($mail, $announced[0]->deliveryId);
        self::assertSame((int) $this->connection->fetchOne('SELECT notification_id FROM notification_delivery WHERE id = ?', [$mail]), $announced[0]->notificationId);

        // A delivery that is gone (e.g. deleted with its instance) announces nothing.
        self::getContainer()->get(NotificationDeliveryAnnouncer::class)->announceSent(999_999);
        self::assertCount(1, $announced);
    }

    public function testPendingDeliveriesAreResumedWhateverTheirAge(): void
    {
        $twoDaysOld = $this->delivery('pending', '2026-10-21 07:00:00');
        $old = $this->delivery('pending', '2026-10-23 07:40:00');
        $this->delivery('pending', '2026-10-23 07:50:00');
        foreach (['queued', 'sent', 'failed'] as $status) {
            $this->delivery($status, '2026-10-20 07:00:00');
        }
        $bus = new RecordingMessageBus();
        $resumer = new PendingDeliveryResumer(new NotificationDeliveryQueue($this->connection, $bus), new MockClock(new \DateTimeImmutable('2026-10-23 08:00:00')));
        $notifications = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification');

        self::assertSame(2, $resumer->resume());
        self::assertEquals([new DeliverNotificationMessage($twoDaysOld), new DeliverNotificationMessage($old)], $bus->messages, 'oldest first');
        self::assertSame($notifications, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification'), 'no new notification is created');
    }

    public function testEachRunResumesABoundedBatchOldestFirst(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->delivery('pending', sprintf('2026-10-2%d 07:00:00', $i));
        }
        $bus = new RecordingMessageBus();
        $queue = new NotificationDeliveryQueue($this->connection, $bus);

        self::assertSame(2, $queue->redispatchPendingCreatedBefore(new \DateTimeImmutable('2026-10-23 08:00:00'), 2));
        self::assertEquals([new DeliverNotificationMessage($ids[0]), new DeliverNotificationMessage($ids[1])], $bus->messages, 'newer deliveries never push out older ones');
    }

    private function delivery(string $status, string $createdAt = '2026-10-23 07:00:00'): int
    {
        $notification = NotificationFactory::createOne(['inAppVisible' => false]);

        return (int) $this->connection->fetchOne(
            "INSERT INTO notification_delivery (notification_id, channel, status, attempt_count, created_at, status_changed_at) VALUES (?, 'email', ?, 1, ?, ?) RETURNING id",
            [$notification->getId(), $status, $createdAt, $createdAt],
        );
    }

    private function emailMessage(int $deliveryId): SendEmailMessage
    {
        $email = new Email();
        $email->getHeaders()->addTextHeader(NotificationEmailFactory::DELIVERY_ID_HEADER, (string) $deliveryId);

        return new SendEmailMessage($email);
    }

    private function sentEmail(int $deliveryId): SentMessageEvent
    {
        $email = new Email()->from('no-reply@example.test')->to('owner@example.test')->subject('Test')->text('Body');
        $email->getHeaders()->addTextHeader(NotificationEmailFactory::DELIVERY_ID_HEADER, (string) $deliveryId);

        return new SentMessageEvent(new SentMessage($email, new MailerEnvelope(new Address('no-reply@example.test'), [new Address('owner@example.test')])));
    }

    private function failed(object $message, bool $willRetry = false): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'async_mail', new \RuntimeException('SMTP said no'));
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    /**
     * @return array{string, string|null}
     */
    private function state(int $deliveryId): array
    {
        $row = $this->connection->fetchAssociative('SELECT status, reason_code FROM notification_delivery WHERE id = ?', [$deliveryId]);
        self::assertIsArray($row);

        return [(string) $row['status'], null === $row['reason_code'] ? null : (string) $row['reason_code']];
    }
}
