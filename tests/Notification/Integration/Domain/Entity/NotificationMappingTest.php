<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Domain\Entity;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Preference\NotificationChannelResolver;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Notification\Domain\Entity\Notification;
use App\Notification\Domain\Entity\NotificationDeduplication;
use App\Notification\Domain\Entity\NotificationDelivery;
use App\Notification\Domain\Entity\NotificationRecipientState;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use App\Notification\Domain\Enum\NotificationDeliveryStatus;
use App\Notification\Domain\Enum\NotificationPriority;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryQueue;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Notification\Infrastructure\Publishing\DbalNotificationPublisher;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Messenger\RecordingMessageBus;
use App\Tests\Support\Notification\Type\HospitalScopedTestNotification;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The publisher writes with DBAL; these tests make sure the entity mapping reads the same columns
 * and that entities built through their constructors can be stored and loaded again.
 */
final class NotificationMappingTest extends DatabaseKernelTestCase
{
    public function testEntitiesReadRowsWrittenByThePublisher(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $publisher = new DbalNotificationPublisher(
            self::getContainer()->get(Connection::class),
            new NotificationRegistry(['test.hospital_scoped' => HospitalScopedTestNotification::class]),
            new NotificationChannelResolver(),
            new MockClock('2026-10-22 12:00:00'),
            new NullLogger(),
            new NotificationDeliveryQueue(self::getContainer()->get(Connection::class), new RecordingMessageBus()),
        );
        $publisher->publish(new NotificationRequest(
            type: 'test.hospital_scoped',
            recipientUserIds: [$userId],
            deduplicationKey: 'test.hospital_scoped:42',
            parameters: ['hospitalName' => 'Klinikum Nord', 'rows' => 12],
            action: new NotificationAction('app_hospital_show', ['id' => 42]),
            scopeReferenceId: 42,
            metadata: ['reference' => 'hospital:42'],
        ));

        $entityManager = $this->entityManager();
        $entityManager->clear();

        $notification = $entityManager->getRepository(Notification::class)->findOneBy(['recipient' => $userId]);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertNotNull($notification->getId());
        self::assertSame($userId, $notification->getRecipient()->getId());
        self::assertSame('test.hospital_scoped', $notification->getType());
        self::assertSame(NotificationCategory::Announcements, $notification->getCategory());
        self::assertSame(NotificationPriority::Normal, $notification->getPriority());
        self::assertSame('notification', $notification->getTranslationDomain());
        self::assertSame('test.hospital_scoped.title', $notification->getTitleKey());
        self::assertSame('test.hospital_scoped.message', $notification->getMessageKey());
        self::assertSame(['hospitalName' => 'Klinikum Nord', 'rows' => 12], $notification->getParameters());
        self::assertSame('app_hospital_show', $notification->getActionRoute());
        self::assertSame(['id' => 42], $notification->getActionParameters());
        self::assertSame(NotificationAccessScope::Hospital, $notification->getAccessScope());
        self::assertSame(42, $notification->getScopeReferenceId());
        self::assertSame(['reference' => 'hospital:42'], $notification->getMetadata());
        self::assertTrue($notification->isInAppVisible());
        self::assertEquals(new \DateTimeImmutable('2026-10-22 12:00:00'), $notification->getCreatedAt());
        self::assertNull($notification->getReadAt());
        self::assertNull($notification->getRemovedAt());
        self::assertNull($notification->getToastPendingUntil());
        self::assertNull($notification->getToastClaimedAt());

        $deduplication = $entityManager->getRepository(NotificationDeduplication::class)->findOneBy(['recipient' => $userId]);
        self::assertInstanceOf(NotificationDeduplication::class, $deduplication);
        self::assertNotNull($deduplication->getId());
        self::assertSame($userId, $deduplication->getRecipient()->getId());
        self::assertSame('test.hospital_scoped:42', $deduplication->getDeduplicationKey());
        self::assertEquals(new \DateTimeImmutable('2026-10-29 12:00:00'), $deduplication->getExpiresAt());
        self::assertSame($notification->getId(), $deduplication->getNotification()?->getId());

        $state = $entityManager->find(NotificationRecipientState::class, $userId);
        self::assertInstanceOf(NotificationRecipientState::class, $state);
        self::assertSame($userId, $state->getUser()->getId());
        self::assertSame(1, $state->getVersion());
        self::assertSame(1, $state->getUnreadCount());
        self::assertEquals(new \DateTimeImmutable('2026-10-22 12:00:00'), $state->getUpdatedAt());
    }

    public function testEntitiesBuiltThroughConstructorsRoundTrip(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $createdAt = new \DateTimeImmutable('2026-10-22 08:30:00');
        $notification = new Notification(
            recipient: $user,
            type: 'test.toast',
            category: NotificationCategory::Imports,
            priority: NotificationPriority::High,
            translationDomain: 'import',
            titleKey: 'test.toast.title',
            messageKey: 'test.toast.message',
            accessScope: NotificationAccessScope::Role,
            inAppVisible: false,
            createdAt: $createdAt,
            parameters: ['count' => 3],
            actionRoute: 'app_import_index',
            actionParameters: ['page' => 2],
            scopeReferenceId: 7,
            metadata: ['reference' => 'import:7'],
            toastPendingUntil: $createdAt->modify('+5 minutes'),
            requiredRole: 'ROLE_ADMIN',
        );
        $deduplication = new NotificationDeduplication($user, 'test.toast:7', $createdAt->modify('+7 days'), $notification);
        $state = new NotificationRecipientState($user, $createdAt, version: 4, unreadCount: 2);

        $entityManager = $this->entityManager();
        $entityManager->persist($notification);
        $entityManager->persist($deduplication);
        $entityManager->persist($state);
        $entityManager->flush();
        $notificationId = $notification->getId();
        $deduplicationId = $deduplication->getId();
        self::assertNotNull($notificationId);
        self::assertNotNull($deduplicationId);
        $entityManager->clear();

        $loaded = $entityManager->find(Notification::class, $notificationId);
        self::assertInstanceOf(Notification::class, $loaded);
        self::assertSame(NotificationCategory::Imports, $loaded->getCategory());
        self::assertSame(NotificationPriority::High, $loaded->getPriority());
        self::assertSame('import', $loaded->getTranslationDomain());
        self::assertSame(NotificationAccessScope::Role, $loaded->getAccessScope());
        self::assertFalse($loaded->isInAppVisible());
        self::assertSame(['count' => 3], $loaded->getParameters());
        self::assertSame('app_import_index', $loaded->getActionRoute());
        self::assertSame(['page' => 2], $loaded->getActionParameters());
        self::assertSame(7, $loaded->getScopeReferenceId());
        self::assertSame('ROLE_ADMIN', $loaded->getRequiredRole());
        self::assertSame(['reference' => 'import:7'], $loaded->getMetadata());
        self::assertEquals($createdAt->modify('+5 minutes'), $loaded->getToastPendingUntil());

        $loadedDeduplication = $entityManager->find(NotificationDeduplication::class, $deduplicationId);
        self::assertInstanceOf(NotificationDeduplication::class, $loadedDeduplication);
        self::assertSame($notificationId, $loadedDeduplication->getNotification()?->getId());

        $loadedState = $entityManager->find(NotificationRecipientState::class, $userId);
        self::assertInstanceOf(NotificationRecipientState::class, $loadedState);
        self::assertSame(4, $loadedState->getVersion());
        self::assertSame(2, $loadedState->getUnreadCount());
    }

    public function testDeliveryRowsAreReadThroughTheEntity(): void
    {
        $notification = NotificationFactory::createOne(['inAppVisible' => false]);
        $connection = self::getContainer()->get(Connection::class);
        $id = (int) $connection->fetchOne(
            "INSERT INTO notification_delivery (notification_id, channel, status, attempt_count, reason_code, created_at, status_changed_at) VALUES (?, 'email', 'failed', 2, 'transport_failed', '2026-10-23 07:00:00', '2026-10-23 07:05:00') RETURNING id",
            [$notification->getId()],
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $delivery = $entityManager->find(NotificationDelivery::class, $id);

        self::assertInstanceOf(NotificationDelivery::class, $delivery);
        self::assertSame($id, $delivery->getId());
        self::assertSame($notification->getId(), $delivery->getNotification()->getId());
        self::assertSame(NotificationChannel::Email, $delivery->getChannel());
        self::assertSame(NotificationDeliveryStatus::Failed, $delivery->getStatus());
        self::assertSame(2, $delivery->getAttemptCount());
        self::assertSame(NotificationDeliveryFailureReason::TransportFailed, $delivery->getReasonCode());
        self::assertSame('2026-10-23 07:00:00', $delivery->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-23 07:05:00', $delivery->getStatusChangedAt()->format('Y-m-d H:i:s'));

        $new = new NotificationDelivery($delivery->getNotification(), NotificationChannel::Email, new \DateTimeImmutable('2026-10-23 08:00:00'));
        self::assertSame(NotificationDeliveryStatus::Pending, $new->getStatus());
        self::assertSame($new->getCreatedAt(), $new->getStatusChangedAt());
    }

    public function testRemovingTheInboxEntryKeepsTheDeduplicationKey(): void
    {
        $user = UserFactory::createOne();
        $createdAt = new \DateTimeImmutable('2026-10-22 08:30:00');
        $notification = new Notification(
            recipient: $user,
            type: 'test.in_app',
            category: NotificationCategory::Announcements,
            priority: NotificationPriority::Normal,
            translationDomain: 'notification',
            titleKey: 'test.in_app.title',
            messageKey: 'test.in_app.message',
            accessScope: NotificationAccessScope::None,
            inAppVisible: true,
            createdAt: $createdAt,
        );
        $deduplication = new NotificationDeduplication($user, 'test.in_app:1', $createdAt->modify('+7 days'), $notification);

        $entityManager = $this->entityManager();
        $entityManager->persist($notification);
        $entityManager->persist($deduplication);
        $entityManager->flush();
        $deduplicationId = $deduplication->getId();

        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM notification WHERE id = ?', [$notification->getId()]);
        $entityManager->clear();

        $loaded = $entityManager->find(NotificationDeduplication::class, $deduplicationId);
        self::assertInstanceOf(NotificationDeduplication::class, $loaded);
        self::assertNull($loaded->getNotification());
        self::assertSame($user->getId(), $loaded->getRecipient()->getId());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
