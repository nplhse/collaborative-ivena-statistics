<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Delivery;

use App\Notification\Application\Message\DeliverNotificationMessage;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryQueue;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryStatusSubscriber;
use App\Notification\Infrastructure\Delivery\NotificationEmailFactory;
use App\Notification\Infrastructure\Delivery\PendingDeliveryReconciler;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Messenger\RecordingMessageBus;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
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
        $subscriber = new NotificationDeliveryStatusSubscriber($this->connection, new MockClock('2026-10-23 08:00:00'), new NullLogger());

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
    }

    public function testReconciliationResumesOnlyDeliveriesPendingForAQuarterOfAnHour(): void
    {
        $old = $this->delivery('pending', '2026-10-23 07:40:00');
        $this->delivery('pending', '2026-10-23 07:50:00');
        $this->delivery('failed', '2026-10-23 07:00:00');
        $bus = new RecordingMessageBus();
        $reconciler = new PendingDeliveryReconciler(new NotificationDeliveryQueue($this->connection, $bus), new MockClock(new \DateTimeImmutable('2026-10-23 08:00:00')));

        self::assertSame(1, $reconciler->reconcile(new \DateTimeImmutable('2026-10-22 08:00:00'), new \DateTimeImmutable('2026-10-23 08:00:00')));
        self::assertEquals([new DeliverNotificationMessage($old)], $bus->messages);
        self::assertSame(0, $reconciler->reconcile(new \DateTimeImmutable('2026-10-23 07:50:00'), new \DateTimeImmutable('2026-10-23 08:00:00')), 'nothing old enough in range');
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
