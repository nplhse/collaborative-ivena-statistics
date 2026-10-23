<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Notification\Application\Event\NotificationDeliveryFailed;
use App\Notification\Application\Message\DeliverNotificationMessage;
use App\Notification\Infrastructure\Delivery\FailedDeliveryReconciler;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryStatusSubscriber;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
     * Failing to record the reminder rolls back the delivery's failure too: the job is retried as a whole and
     * the retry records both. No email is sent on either attempt.
     */
    public function testAFailingReminderUpdateIsRetriedWithTheDelivery(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryId = $this->reminderDelivery($owner, $hospital, $dispatch);
        $owner->setIsEnabled(false);
        $this->entityManager()->flush();
        $this->failNextAnnouncement();

        try {
            self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeliverNotificationMessage($deliveryId));
            self::fail('The failing reminder update must fail the delivery job.');
        } catch (\Throwable) {
        }

        self::assertSame('pending', $this->deliveryStatus($deliveryId), 'rolled back: the job can run again');
        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch));

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeliverNotificationMessage($deliveryId));

        self::assertEmailCount(0);
        self::assertSame('failed', $this->deliveryStatus($deliveryId));
        self::assertSame([MonthlyReminderDispatchStatus::Failed, 'notification_delivery:recipient_unavailable'], $this->dispatchState($dispatch));
    }

    /**
     * When Messenger gives up a job it does not retry it; the delivery keeps its final state and the hourly
     * reconciliation records the reminder's failure later, without sending anything again.
     */
    public function testReconciliationCatchesUpWhenTheFinalFailureCouldNotBeRecorded(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryId = $this->reminderDelivery($owner, $hospital, $dispatch, 'queued');
        $this->failNextAnnouncement();

        self::getContainer()->get(NotificationDeliveryStatusSubscriber::class)->onMessageFailed(
            new WorkerMessageFailedEvent(new Envelope(new DeliverNotificationMessage($deliveryId)), 'async_priority_low', new \RuntimeException('gave up')),
        );
        self::assertSame('failed', $this->deliveryStatus($deliveryId), 'the final state is kept');
        self::assertSame(MonthlyReminderDispatchStatus::Queued, $this->dispatchState($dispatch)[0]);

        $reconciler = self::getContainer()->get(FailedDeliveryReconciler::class);
        $this->failNextAnnouncement();
        self::assertSame(0, $reconciler->reconcile(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour')), 'a failing producer is logged and skipped');
        self::assertSame(1, $reconciler->reconcile(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour')));

        self::assertSame([MonthlyReminderDispatchStatus::Failed, 'notification_delivery:delivery_job_failed'], $this->dispatchState($dispatch));
        self::assertEmailCount(0);
        self::assertSame('failed', $this->deliveryStatus($deliveryId), 'nothing is sent again');
    }

    public function testAFailureOfAnEarlierAttemptLeavesTheCurrentAttemptAlone(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $earlier = $this->reminderDelivery($owner, $hospital, $dispatch, 'failed', 'attempt-a');
        $dispatch->startAttempt('attempt-b');
        $this->entityManager()->flush();

        self::getContainer()->get(FailedDeliveryReconciler::class)->reconcile(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour'));
        self::getContainer()->get(NotificationDeliveryStatusSubscriber::class)->onMessageFailed(
            new WorkerMessageFailedEvent(new Envelope(new DeliverNotificationMessage($earlier)), 'async_priority_low', new \RuntimeException('late')),
        );

        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch), 'attempt B is still running');
    }

    /**
     * Attempt A's email is retried by the mailer, attempt B starts on the same dispatch, then the mailer gives
     * A up for good: the failure belongs to A and must not fail B.
     */
    public function testALateMailerFailureOfAnEarlierAttemptLeavesTheCurrentAttemptAlone(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryOfA = $this->reminderDelivery($owner, $hospital, $dispatch, 'queued', 'attempt-a');
        $dispatch->startAttempt('attempt-b');
        $this->entityManager()->flush();

        $email = new \Symfony\Component\Mime\Email();
        $email->getHeaders()->addTextHeader(\App\Notification\Infrastructure\Delivery\NotificationEmailFactory::DELIVERY_ID_HEADER, (string) $deliveryOfA);
        $email->getHeaders()->addTextHeader(\App\Engagement\Infrastructure\Notification\MonthlyReminderEmailCustomizer::DISPATCH_ID_HEADER, (string) $dispatch->getId());
        $email->getHeaders()->addTextHeader(\App\Engagement\Infrastructure\Notification\MonthlyReminderEmailCustomizer::ATTEMPT_ID_HEADER, 'attempt-a');
        self::getContainer()->get(NotificationDeliveryStatusSubscriber::class)->onMessageFailed(
            new WorkerMessageFailedEvent(new Envelope(new \Symfony\Component\Mailer\Messenger\SendEmailMessage($email)), 'async_mail', new \RuntimeException('550 mailbox unavailable')),
        );

        self::assertSame('failed', $this->deliveryStatus($deliveryOfA), 'attempt A failed for good');
        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch), 'attempt B is still running');
    }

    private function failNextAnnouncement(): void
    {
        $failed = false;
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NotificationDeliveryFailed::class,
            static function () use (&$failed): void {
                if (!$failed) {
                    $failed = true;

                    throw new \RuntimeException('database briefly gone');
                }
            },
            100,
        );
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
        $dispatch->startAttempt('attempt-a');
        $this->entityManager()->persist($dispatch);
        $this->entityManager()->flush();

        return $dispatch;
    }

    private function reminderDelivery(User $owner, Hospital $hospital, MonthlyReminderDispatch $dispatch, string $status = 'pending', ?string $attemptId = 'attempt-a'): int
    {
        $notification = NotificationFactory::createOne([
            'recipient' => $owner,
            'type' => 'engagement.monthly_reminder',
            'titleKey' => 'engagement.monthly_reminder.title',
            'messageKey' => 'engagement.monthly_reminder.message',
            'inAppVisible' => false,
            'parameters' => ['hospitalId' => $hospital->getId(), 'hospitalName' => 'Klinikum', 'referenceDate' => '2026-10-01', 'period' => 1788264000, 'dispatchId' => $dispatch->getId(), 'attemptId' => $attemptId],
        ]);

        return (int) $this->connection()->fetchOne(
            "INSERT INTO notification_delivery (notification_id, channel, status, attempt_count, created_at, status_changed_at) VALUES (?, 'email', ?, 0, NOW(), NOW()) RETURNING id",
            [$notification->getId(), $status],
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
