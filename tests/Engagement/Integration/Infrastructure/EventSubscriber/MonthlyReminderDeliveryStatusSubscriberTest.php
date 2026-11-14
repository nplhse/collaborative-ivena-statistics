<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Notification\Application\Event\NotificationDeliveryFailed;
use App\Notification\Application\Event\NotificationDeliverySent;
use App\Notification\Application\Message\DeliverNotificationMessage;
use App\Notification\Infrastructure\Delivery\DeliveryOutcomeReconciler;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryStatusSubscriber;
use App\Notification\Infrastructure\Delivery\NotificationEmailFactory;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope as MailerEnvelope;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The dispatch follows the outcome of its notification's delivery (ADR 016, ADR 019): sent when the email went
 * out, failed when it failed for good (also before the mailer), otherwise the dispatch stays queued and blocks the
 * next scheduled attempt.
 */
final class MonthlyReminderDeliveryStatusSubscriberTest extends DatabaseKernelTestCase
{
    public function testAnAcceptedEmailMarksTheDispatchAsSent(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryId = $this->reminderDelivery($owner, $hospital, $dispatch, 'queued');

        $this->subscriber()->onSent($this->sentEmail($deliveryId));

        self::assertSame('sent', $this->deliveryStatus($deliveryId));
        self::assertSame([MonthlyReminderDispatchStatus::Sent, null], $this->dispatchState($dispatch));
        self::assertNotNull($dispatch->getDeliveredAt());
    }

    /**
     * Attempt A is delayed by mailer retries, an admin starts attempt B on the same dispatch, then A's email
     * goes out after all: B is still running and must not be marked as sent by A.
     */
    public function testALateSuccessOfAnEarlierAttemptLeavesTheCurrentAttemptAlone(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryOfA = $this->reminderDelivery($owner, $hospital, $dispatch, 'queued', 'attempt-a');
        $dispatch->startAttempt('attempt-b');
        $this->entityManager()->flush();

        $this->subscriber()->onSent($this->sentEmail($deliveryOfA));

        self::assertSame('sent', $this->deliveryStatus($deliveryOfA));
        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch), 'attempt B is still running');
    }

    public function testDispatchesFromBeforeAttemptIdsMatchNotificationsWithoutOne(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = new MonthlyReminderDispatch($hospital, '2026-09', 'scheduler', new \DateTimeImmutable(), MonthlyReminderDispatchStatus::Queued, 'owner@example.test');
        $this->entityManager()->persist($dispatch);
        $this->entityManager()->flush();
        $deliveryId = $this->reminderDelivery($owner, $hospital, $dispatch, 'queued', null);

        $this->subscriber()->onSent($this->sentEmail($deliveryId));

        self::assertSame(MonthlyReminderDispatchStatus::Sent, $this->dispatchState($dispatch)[0]);
    }

    public function testASuccessForAnotherHospitalOrAnotherTypeLeavesTheDispatchAlone(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $otherHospital = HospitalFactory::createOne(['owner' => UserFactory::new()]);
        $wrongHospital = $this->reminderDelivery($owner, $otherHospital, $dispatch, 'queued');
        $otherType = $this->reminderDelivery($owner, $hospital, $dispatch, 'queued', 'attempt-a', 'import.completed');

        $this->subscriber()->onSent($this->sentEmail($wrongHospital));
        $this->subscriber()->onSent($this->sentEmail($otherType));

        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch));
    }

    /**
     * The email is out, so a failing dispatch update must not fail the mail job (Messenger would send it again).
     * The delivery stays sent, and the hourly reconciliation completes the dispatch later.
     */
    public function testReconciliationCompletesTheDispatchWhenTheSuccessCouldNotBeRecorded(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryId = $this->reminderDelivery($owner, $hospital, $dispatch, 'queued');
        $this->failNextAnnouncement(NotificationDeliverySent::class);

        $this->subscriber()->onSent($this->sentEmail($deliveryId));

        self::assertSame('sent', $this->deliveryStatus($deliveryId));
        self::assertSame(MonthlyReminderDispatchStatus::Queued, $this->dispatchState($dispatch)[0]);

        self::assertSame(1, $this->reconciler()->reconcile(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour')));

        self::assertSame(MonthlyReminderDispatchStatus::Sent, $this->dispatchState($dispatch)[0]);
        self::assertEmailCount(0);
    }

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

    /**
     * The failure is recorded in the audit log (#1173) with the dispatch, its hospital, the reporting month (the
     * month before the dispatch's upload month 2026-09) and the reason code; the hourly reconciliation announces
     * the same failure again and writes nothing more.
     */
    public function testAFailedDeliveryIsRecordedOnceInTheAuditLog(): void
    {
        [$owner, $hospital] = $this->ownerWithHospital();
        $dispatch = $this->dispatch($hospital);
        $deliveryId = $this->reminderDelivery($owner, $hospital, $dispatch);
        $owner->setIsEnabled(false);
        $this->entityManager()->flush();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeliverNotificationMessage($deliveryId));

        $entries = $this->failureEntries($dispatch);
        self::assertCount(1, $entries);
        self::assertSame([
            'action' => 'update',
            'entity_class' => MonthlyReminderDispatch::class,
            'outcome' => 'failed',
            'changes' => [
                'status' => ['old' => 'queued', 'new' => 'failed'],
                'failureReason' => ['old' => null, 'new' => 'notification_delivery:recipient_unavailable'],
            ],
            'intent_metadata' => [
                'hospital_id' => $hospital->getId(),
                'reporting_period' => '2026-08',
                'reason' => 'recipient_unavailable',
            ],
        ], $entries[0]);

        self::assertSame(1, $this->reconciler()->reconcile(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour')));
        self::assertCount(1, $this->failureEntries($dispatch), 'a repeated announcement changes nothing and writes nothing');
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
        self::assertSame([], $this->failureEntries($dispatch), 'a retry follows: nothing to record yet');

        $subscriber->onMessageFailed(new WorkerMessageFailedEvent(new Envelope(new DeliverNotificationMessage($deliveryId)), 'async_priority_low', new \RuntimeException('still broken')));
        self::assertSame([MonthlyReminderDispatchStatus::Failed, 'notification_delivery:delivery_job_failed'], $this->dispatchState($dispatch));
        self::assertSame(['delivery_job_failed'], array_map(static fn (array $entry): mixed => $entry['intent_metadata']['reason'] ?? null, $this->failureEntries($dispatch)));
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
        self::assertSame([], $this->failureEntries($dispatch), 'no dispatch changed: no audit entry');
        self::assertSame([], $this->failureEntries($sentDispatch));
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
        self::assertSame([], $this->failureEntries($dispatch), 'the audit entry rolls back with the failure');

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeliverNotificationMessage($deliveryId));

        self::assertEmailCount(0);
        self::assertSame('failed', $this->deliveryStatus($deliveryId));
        self::assertSame([MonthlyReminderDispatchStatus::Failed, 'notification_delivery:recipient_unavailable'], $this->dispatchState($dispatch));
        self::assertCount(1, $this->failureEntries($dispatch));
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

        $reconciler = $this->reconciler();
        $this->failNextAnnouncement();
        self::assertSame(0, $reconciler->reconcile(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour')), 'a failing producer is logged and skipped');
        self::assertSame(1, $reconciler->reconcile(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour')));

        self::assertSame([MonthlyReminderDispatchStatus::Failed, 'notification_delivery:delivery_job_failed'], $this->dispatchState($dispatch));
        self::assertCount(1, $this->failureEntries($dispatch), 'recorded when the reconciliation records the failure');
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

        $this->reconciler()->reconcile(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour'));
        self::getContainer()->get(NotificationDeliveryStatusSubscriber::class)->onMessageFailed(
            new WorkerMessageFailedEvent(new Envelope(new DeliverNotificationMessage($earlier)), 'async_priority_low', new \RuntimeException('late')),
        );

        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch), 'attempt B is still running');
        self::assertSame([], $this->failureEntries($dispatch));
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

        $email = new Email();
        $email->getHeaders()->addTextHeader(NotificationEmailFactory::DELIVERY_ID_HEADER, (string) $deliveryOfA);
        self::getContainer()->get(NotificationDeliveryStatusSubscriber::class)->onMessageFailed(
            new WorkerMessageFailedEvent(new Envelope(new \Symfony\Component\Mailer\Messenger\SendEmailMessage($email)), 'async_mail', new \RuntimeException('550 mailbox unavailable')),
        );

        self::assertSame('failed', $this->deliveryStatus($deliveryOfA), 'attempt A failed for good');
        self::assertSame([MonthlyReminderDispatchStatus::Queued, null], $this->dispatchState($dispatch), 'attempt B is still running');
        self::assertSame([], $this->failureEntries($dispatch), 'attempt B did not fail');
    }

    /**
     * @param class-string $event
     */
    private function failNextAnnouncement(string $event = NotificationDeliveryFailed::class): void
    {
        $failed = false;
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            $event,
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

    private function reminderDelivery(User $owner, Hospital $hospital, MonthlyReminderDispatch $dispatch, string $status = 'pending', ?string $attemptId = 'attempt-a', string $type = 'engagement.monthly_reminder'): int
    {
        $notification = NotificationFactory::createOne([
            'recipient' => $owner,
            'type' => $type,
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

    private function sentEmail(int $deliveryId): SentMessageEvent
    {
        $email = new Email()->from('no-reply@example.test')->to('owner@example.test')->subject('Reminder')->text('Reminder body');
        $email->getHeaders()->addTextHeader(NotificationEmailFactory::DELIVERY_ID_HEADER, (string) $deliveryId);

        return new SentMessageEvent(new SentMessage($email, new MailerEnvelope(new Address('no-reply@example.test'), [new Address('owner@example.test')])));
    }

    private function subscriber(): NotificationDeliveryStatusSubscriber
    {
        return self::getContainer()->get(NotificationDeliveryStatusSubscriber::class);
    }

    private function reconciler(): DeliveryOutcomeReconciler
    {
        return self::getContainer()->get(DeliveryOutcomeReconciler::class);
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

    /**
     * Audit entries of failed deliveries of the dispatch, oldest first.
     *
     * @return list<array{action: string, entity_class: string, outcome: string|null, changes: array<string, mixed>, intent_metadata: array<string, mixed>}>
     */
    private function failureEntries(MonthlyReminderDispatch $dispatch): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT action, entity_class, outcome, changes, metadata FROM audit_log WHERE intent = ? AND entity_id = ? ORDER BY id',
            [AuditEntry::INTENT_HOSPITAL_REMINDER_FAILED, (string) $dispatch->getId()],
        );

        return array_map(static function (array $row): array {
            /** @var array<string, mixed> $changes */
            $changes = json_decode((string) $row['changes'], true, flags: \JSON_THROW_ON_ERROR);
            /** @var array{intent_metadata?: array<string, mixed>} $metadata */
            $metadata = json_decode((string) $row['metadata'], true, flags: \JSON_THROW_ON_ERROR);

            return [
                'action' => (string) $row['action'],
                'entity_class' => (string) $row['entity_class'],
                'outcome' => null === $row['outcome'] ? null : (string) $row['outcome'],
                'changes' => $changes,
                'intent_metadata' => $metadata['intent_metadata'] ?? [],
            ];
        }, $rows);
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
