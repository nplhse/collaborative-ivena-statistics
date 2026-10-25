<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Delivery;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Message\DeliverNotificationMessage;
use App\Notification\Domain\Entity\Notification;
use App\Notification\Infrastructure\Delivery\DeliverNotificationMessageHandler;
use App\Notification\Infrastructure\Delivery\NotificationEmailFactory;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\Type\EmailTestNotification;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Notifier\NotifierInterface;

final class DeliverNotificationMessageHandlerTest extends DatabaseKernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testSendsTheEmailOnceAndRecordsItsDelivery(): void
    {
        $user = UserFactory::createOne(['email' => 'reader@example.test']);
        $deliveryId = $this->delivery($this->notification($user, ['actionRoute' => 'app_import_index']));

        $this->handler()(new DeliverNotificationMessage($deliveryId));
        $this->handler()(new DeliverNotificationMessage($deliveryId));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(['reader@example.test'], array_map(static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('Import "Januar" finished', $email->getSubject());
        self::assertStringContainsString('All notifications', (string) $email->getHtmlBody(), 'the generic notification email');
        self::assertSame((string) $deliveryId, $email->getHeaders()->get(NotificationEmailFactory::DELIVERY_ID_HEADER)?->getBodyAsString());
        self::assertMatchesRegularExpression('#href="https?://[^"]+/import"#', (string) $email->getHtmlBody(), 'absolute link to the entry');
        self::assertStringContainsString('Klinikum Test: 5 of 5 rows imported.', (string) $email->getHtmlBody());

        self::assertSame(['sent', 1], $this->deliveryState($deliveryId), 'the mail transport accepted it; the second job found nothing pending');
    }

    public function testDisabledRecipientsAndUnusableAddressesFailWithAReasonCode(): void
    {
        $disabled = $this->delivery($this->notification(UserFactory::createOne(['isEnabled' => false])));
        $user = UserFactory::createOne();
        $user->setEmail('');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $withoutAddress = $this->delivery($this->notification($user));

        $this->handler()(new DeliverNotificationMessage($disabled));
        $this->handler()(new DeliverNotificationMessage($withoutAddress));
        $this->handler()(new DeliverNotificationMessage(999_999));

        self::assertEmailCount(0);
        self::assertSame('recipient_unavailable', $this->connection->fetchOne('SELECT reason_code FROM notification_delivery WHERE id = ?', [$disabled]));
        self::assertSame(['failed', 1], $this->deliveryState($withoutAddress));
    }

    public function testFailedDeliveriesAreNeverSentAgain(): void
    {
        $deliveryId = $this->delivery($this->notification(UserFactory::createOne()));
        $this->connection->executeStatement("UPDATE notification_delivery SET status = 'failed' WHERE id = ?", [$deliveryId]);

        $this->handler()(new DeliverNotificationMessage($deliveryId));

        self::assertEmailCount(0);
        self::assertSame(['failed', 0], $this->deliveryState($deliveryId));
    }

    private function handler(): DeliverNotificationMessageHandler
    {
        $container = self::getContainer();

        return new DeliverNotificationMessageHandler(
            $this->connection,
            new NotificationRegistry(['test.email' => EmailTestNotification::class]),
            $container->get(NotificationEmailFactory::class),
            $container->get(NotifierInterface::class),
            $container->get(UserRepository::class),
            new MockClock('2026-10-23 08:00:00'),
            new NullLogger(),
            $container->get(\App\Notification\Infrastructure\Delivery\NotificationDeliveryFailureAnnouncer::class),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function notification(User $user, array $attributes = []): Notification
    {
        return NotificationFactory::createOne([
            'recipient' => $user,
            'type' => 'test.email',
            'titleKey' => 'import.completed.title',
            'messageKey' => 'import.completed.message',
            'parameters' => ['importName' => 'Januar', 'hospitalName' => 'Klinikum Test', 'rowsTotal' => 5, 'rowsOk' => 5, 'rowsRejected' => 0],
            ...$attributes,
        ]);
    }

    private function delivery(Notification $notification): int
    {
        return (int) $this->connection->fetchOne(
            "INSERT INTO notification_delivery (notification_id, channel, status, attempt_count, created_at, status_changed_at) VALUES (?, 'email', 'pending', 0, NOW(), NOW()) RETURNING id",
            [$notification->getId()],
        );
    }

    /**
     * @return array{string, int}
     */
    private function deliveryState(int $deliveryId): array
    {
        $row = $this->connection->fetchAssociative('SELECT status, attempt_count FROM notification_delivery WHERE id = ?', [$deliveryId]);
        self::assertIsArray($row);

        return [(string) $row['status'], (int) $row['attempt_count']];
    }
}
