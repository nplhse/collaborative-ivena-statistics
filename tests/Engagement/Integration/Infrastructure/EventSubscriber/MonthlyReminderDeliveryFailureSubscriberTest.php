<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Notification\Application\Message\DeliverNotificationMessage;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryStatusSubscriber;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A reminder email that fails for good before the mailer must fail its dispatch too; otherwise the dispatch
 * stays queued and blocks the next scheduled attempt (ADR 016).
 */
final class MonthlyReminderDeliveryFailureSubscriberTest extends DatabaseKernelTestCase
{
    public function testDisabledRecipientFailsTheDispatchBeforeTheMailer(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryId = $this->reminderDelivery($owner, $hospital, $dispatch);
        $owner->setIsEnabled(false);
        $this->entityManager()->flush();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeliverNotificationMessage($deliveryId));

        self::assertEmailCount(0);
        self::assertSame('failed', $this->deliveryStatus($deliveryId));
        self::assertSame([MonthlyReminderDispatchStatus::Failed, 'notification_delivery:recipient_unavailable'], $this->dispatchState($dispatch));
    }

    public function testDeliveryJobOutOfRetriesFailsTheDispatchButARetryDoesNot(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryId = $this->reminderDelivery($owner, $hospital, $dispatch);
        $subscriber = self::getContainer()->get(NotificationDeliveryStatusSubscriber::class);

        $retry = new WorkerMessageFailedEvent(new Envelope(new DeliverNotificationMessage($deliveryId)), 'async_priority_low', new \RuntimeException('database hiccup'));
        $retry->setForRetry();
        $subscriber->onMessageFailed($retry);
        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch), 'a retry follows: not failed yet');
        self::assertSame('pending', $this->deliveryStatus($deliveryId));

        $subscriber->onMessageFailed(new WorkerMessageFailedEvent(new Envelope(new DeliverNotificationMessage($deliveryId)), 'async_priority_low', new \RuntimeException('still broken')));
        self::assertSame([MonthlyReminderDispatchStatus::Failed, 'notification_delivery:delivery_job_failed'], $this->dispatchState($dispatch));
    }

    public function testOnlyTheMatchingQueuedDispatchIsTouched(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $otherHospital = HospitalFactory::createOne(['owner' => UserFactory::new()]);
        $deliveryId = $this->reminderDelivery($owner, $otherHospital, $dispatch);
        $sentDispatch = $this->dispatch($hospital, MonthlyReminderDispatchStatus::Sent, '2026-08');
        $secondDelivery = $this->reminderDelivery($owner, $hospital, $sentDispatch);
        $subscriber = self::getContainer()->get(NotificationDeliveryStatusSubscriber::class);

        foreach ([$deliveryId, $secondDelivery] as $id) {
            $subscriber->onMessageFailed(new WorkerMessageFailedEvent(new Envelope(new DeliverNotificationMessage($id)), 'async_priority_low', new \RuntimeException('broken')));
        }

        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch), 'another hospital in the notification: not this dispatch');
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $this->dispatchState($sentDispatch)[0], 'a completed dispatch stays as it is');
    }

    /**
     * @return array{User, Hospital}
     */
    private function ownerWithHospital(): array
    {
        $owner = UserFactory::createOne(['email' => sprintf('reminder-%s@example.test', bin2hex(random_bytes(4))), 'isVerified' => true]);
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'isParticipating' => true]);

        return [$owner, $hospital];
    }

    private function dispatch(Hospital $hospital, MonthlyReminderDispatchStatus $status = MonthlyReminderDispatchStatus::Queued, string $period = '2026-09'): MonthlyReminderDispatch
    {
        $dispatch = new MonthlyReminderDispatch($hospital, $period, 'scheduler', new \DateTimeImmutable(), $status, 'owner@example.test');
        $this->entityManager()->persist($dispatch);
        $this->entityManager()->flush();

        return $dispatch;
    }

    private function reminderDelivery(User $owner, Hospital $hospital, MonthlyReminderDispatch $dispatch): int
    {
        $notification = NotificationFactory::createOne([
            'recipient' => $owner,
            'type' => 'engagement.monthly_reminder',
            'titleKey' => 'engagement.monthly_reminder.title',
            'messageKey' => 'engagement.monthly_reminder.message',
            'inAppVisible' => false,
            'parameters' => ['hospitalId' => $hospital->getId(), 'hospitalName' => 'Klinikum', 'referenceDate' => '2026-10-01', 'period' => 1788264000, 'dispatchId' => $dispatch->getId()],
        ]);

        return (int) $this->connection()->fetchOne(
            "INSERT INTO notification_delivery (notification_id, channel, status, attempt_count, created_at, status_changed_at) VALUES (?, 'email', 'pending', 0, NOW(), NOW()) RETURNING id",
            [$notification->getId()],
        );
    }

    private function deliveryStatus(int $deliveryId): string
    {
        return (string) $this->connection()->fetchOne('SELECT status FROM notification_delivery WHERE id = ?', [$deliveryId]);
    }

    /**
     * @return array{MonthlyReminderDispatchStatus, string|null}
     */
    private function dispatchState(MonthlyReminderDispatch $dispatch): array
    {
        $this->entityManager()->refresh($dispatch);

        return [$dispatch->getStatus(), $dispatch->getFailureReason()];
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
