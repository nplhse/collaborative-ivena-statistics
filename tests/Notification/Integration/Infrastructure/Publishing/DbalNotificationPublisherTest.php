<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Publishing;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Definition\UnknownNotificationTypeException;
use App\Notification\Application\Preference\NotificationChannelResolver;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Notification\Infrastructure\Preference\DbalNotificationPreferenceStore;
use App\Notification\Infrastructure\Publishing\DbalNotificationPublisher;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\Type\FixedTestNotification;
use App\Tests\Support\Notification\Type\HospitalScopedTestNotification;
use App\Tests\Support\Notification\Type\InAppTestNotification;
use App\Tests\Support\Notification\Type\OptInTestNotification;
use App\Tests\Support\Notification\Type\RoleScopedTestNotification;
use App\Tests\Support\Notification\Type\ToastTestNotification;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class DbalNotificationPublisherTest extends DatabaseKernelTestCase
{
    private Connection $connection;
    private MockClock $clock;
    private BufferingLogger $logger;
    private DbalNotificationPublisher $publisher;
    private DbalNotificationPreferenceStore $preferences;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->clock = new MockClock('2026-10-22 12:00:00');
        $this->logger = new BufferingLogger();
        $this->preferences = new DbalNotificationPreferenceStore($this->connection);
        $this->publisher = new DbalNotificationPublisher(
            $this->connection,
            new NotificationRegistry([
                'test.in_app' => InAppTestNotification::class,
                'test.toast' => ToastTestNotification::class,
                'test.hospital_scoped' => HospitalScopedTestNotification::class,
                'test.opt_in' => OptInTestNotification::class,
                'test.role_scoped' => RoleScopedTestNotification::class,
                'test.fixed' => FixedTestNotification::class,
            ]),
            new NotificationChannelResolver(),
            $this->preferences,
            $this->clock,
            $this->logger,
        );
    }

    public function testStoresInboxEntryAndUpdatesRecipientState(): void
    {
        $userId = $this->createUserId();

        $this->publisher->publish(new NotificationRequest(
            type: 'test.in_app',
            recipientUserIds: [$userId],
            deduplicationKey: 'test.in_app:1',
            parameters: ['importName' => 'Januar'],
            action: new NotificationAction('app_import_show', ['id' => 7]),
            metadata: ['reference' => 'import:7'],
        ));

        $row = $this->connection->fetchAssociative('SELECT * FROM notification WHERE recipient_id = ?', [$userId]);
        self::assertIsArray($row);
        self::assertSame('test.in_app', $row['type']);
        self::assertSame('announcements', $row['category']);
        self::assertSame('normal', $row['priority']);
        self::assertSame('notification', $row['translation_domain']);
        self::assertSame('test.in_app.title', $row['title_key']);
        self::assertSame('test.in_app.message', $row['message_key']);
        self::assertSame(['importName' => 'Januar'], json_decode((string) $row['parameters'], true));
        self::assertSame('app_import_show', $row['action_route']);
        self::assertSame(['id' => 7], json_decode((string) $row['action_parameters'], true));
        self::assertSame('none', $row['access_scope']);
        self::assertTrue((bool) $row['in_app_visible']);
        self::assertSame('2026-10-22 12:00:00', $row['created_at']);
        self::assertNull($row['toast_pending_until']);
        self::assertNull($row['required_role']);

        self::assertSame(['version' => 1, 'unread_count' => 1], $this->recipientState($userId));

        $deduplication = $this->connection->fetchAssociative('SELECT * FROM notification_deduplication WHERE recipient_id = ?', [$userId]);
        self::assertIsArray($deduplication);
        self::assertSame($row['id'], $deduplication['notification_id']);
        self::assertSame('2026-10-29 12:00:00', $deduplication['expires_at']);
    }

    public function testRoleScopedTypeStoresRequiredRole(): void
    {
        $userId = $this->createUserId();

        $this->publisher->publish(new NotificationRequest('test.role_scoped', [$userId], 'test.role_scoped:1'));

        self::assertSame(
            ['access_scope' => 'role', 'required_role' => 'ROLE_ADMIN'],
            $this->connection->fetchAssociative('SELECT access_scope, required_role FROM notification WHERE recipient_id = ?', [$userId]),
        );
    }

    public function testToastTypeOpensToastWindow(): void
    {
        $userId = $this->createUserId();

        $this->publisher->publish(new NotificationRequest('test.toast', [$userId], 'test.toast:1'));

        self::assertSame(
            '2026-10-22 12:05:00',
            $this->connection->fetchOne('SELECT toast_pending_until FROM notification WHERE recipient_id = ?', [$userId]),
        );
    }

    public function testDuplicateKeyCreatesNothingNew(): void
    {
        $userId = $this->createUserId();
        $request = new NotificationRequest('test.in_app', [$userId], 'test.in_app:dup');

        $this->publisher->publish($request);
        $this->clock->modify('+1 day');
        $this->publisher->publish($request);

        self::assertSame(1, $this->countNotifications($userId));
        self::assertSame(['version' => 1, 'unread_count' => 1], $this->recipientState($userId));
    }

    public function testExpiredKeyIsTakenOver(): void
    {
        $userId = $this->createUserId();
        $request = new NotificationRequest('test.in_app', [$userId], 'test.in_app:expired');

        $this->publisher->publish($request);
        $this->clock->modify('+8 days');
        $this->publisher->publish($request);

        self::assertSame(2, $this->countNotifications($userId));
        self::assertSame(['version' => 2, 'unread_count' => 2], $this->recipientState($userId));

        $newest = $this->connection->fetchOne('SELECT MAX(id) FROM notification WHERE recipient_id = ?', [$userId]);
        $deduplication = $this->connection->fetchAllAssociative('SELECT * FROM notification_deduplication WHERE recipient_id = ?', [$userId]);
        self::assertCount(1, $deduplication);
        self::assertSame($newest, $deduplication[0]['notification_id']);
        self::assertSame('2026-11-06 12:00:00', $deduplication[0]['expires_at']);
    }

    public function testSameKeyIsIndependentPerRecipient(): void
    {
        $first = $this->createUserId();
        $second = $this->createUserId();

        $this->publisher->publish(new NotificationRequest('test.in_app', [$first, $second, $first], 'test.in_app:shared'));

        self::assertSame(1, $this->countNotifications($first));
        self::assertSame(1, $this->countNotifications($second));
    }

    public function testTypeWithoutResolvedChannelsStoresNothing(): void
    {
        $userId = $this->createUserId();

        $this->publisher->publish(new NotificationRequest('test.opt_in', [$userId], 'test.opt_in:1'));

        self::assertSame(0, $this->countNotifications($userId));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification_deduplication WHERE recipient_id = ?', [$userId]));
        self::assertFalse($this->recipientState($userId));
    }

    public function testCategoryOverrideEnablesAnOptInType(): void
    {
        $userId = $this->createUserId();
        $this->preferences->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::InApp, true),
        ]);

        $this->publisher->publish(new NotificationRequest('test.opt_in', [$userId], 'test.opt_in:category'));

        self::assertSame(1, $this->countNotifications($userId));
    }

    public function testTypeOverrideBeatsCategoryOverride(): void
    {
        $userId = $this->createUserId();
        $this->preferences->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::InApp, true),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.opt_in', NotificationChannel::InApp, false),
        ]);

        $this->publisher->publish(new NotificationRequest('test.opt_in', [$userId], 'test.opt_in:type'));

        self::assertSame(0, $this->countNotifications($userId));
        self::assertFalse($this->recipientState($userId));
    }

    public function testDisabledToastKeepsTheInboxEntryWithoutToastWindow(): void
    {
        $userId = $this->createUserId();
        $this->preferences->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::Toast, false),
        ]);

        $this->publisher->publish(new NotificationRequest('test.toast', [$userId], 'test.toast:no-toast'));

        self::assertSame(1, $this->countNotifications($userId));
        self::assertNull($this->connection->fetchOne('SELECT toast_pending_until FROM notification WHERE recipient_id = ?', [$userId]));
    }

    public function testDisabledInAppStoresNothingEvenWithToastEnabled(): void
    {
        $userId = $this->createUserId();
        $this->preferences->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::InApp, false),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::Toast, true),
        ]);

        $this->publisher->publish(new NotificationRequest('test.toast', [$userId], 'test.toast:no-in-app'));

        self::assertSame(0, $this->countNotifications($userId));
    }

    public function testNonConfigurableTypeIgnoresOverrides(): void
    {
        $userId = $this->createUserId();
        $this->preferences->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::InApp, false),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.fixed', NotificationChannel::InApp, false),
        ]);

        $this->publisher->publish(new NotificationRequest('test.fixed', [$userId], 'test.fixed:1'));

        self::assertSame(1, $this->countNotifications($userId));
    }

    public function testOverridesOfOtherUsersDoNotApply(): void
    {
        $userId = $this->createUserId();
        $otherId = $this->createUserId();
        $this->preferences->save($otherId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.in_app', NotificationChannel::InApp, false),
        ]);

        $this->publisher->publish(new NotificationRequest('test.in_app', [$userId, $otherId], 'test.in_app:other-user'));

        self::assertSame(1, $this->countNotifications($userId));
        self::assertSame(0, $this->countNotifications($otherId));
    }

    public function testFailureForOneRecipientIsLoggedAndOthersAreStored(): void
    {
        $userId = $this->createUserId();
        $missingUserId = $userId + 100_000;

        $this->publisher->publish(new NotificationRequest('test.in_app', [$missingUserId, $userId], 'test.in_app:partial'));

        self::assertSame(1, $this->countNotifications($userId));
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('error', $logs[0][0]);
        self::assertSame('notification.publish_failed', $logs[0][1]);
        self::assertSame($missingUserId, $logs[0][2]['recipient_id']);
    }

    public function testJoinsTransactionOfTheCaller(): void
    {
        $userId = $this->createUserId();

        $this->connection->beginTransaction();
        $this->publisher->publish(new NotificationRequest('test.in_app', [$userId], 'test.in_app:rollback'));
        $this->connection->rollBack();

        self::assertSame(0, $this->countNotifications($userId));
        self::assertFalse($this->recipientState($userId));
    }

    public function testRejectsUnknownType(): void
    {
        $this->expectException(UnknownNotificationTypeException::class);

        $this->publisher->publish(new NotificationRequest('test.unknown', [1], 'key'));
    }

    public function testRejectsEmptyDeduplicationKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a deduplication key');

        $this->publisher->publish(new NotificationRequest('test.in_app', [1], ' '));
    }

    public function testRejectsHospitalScopedTypeWithoutReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a scope reference');

        $this->publisher->publish(new NotificationRequest('test.hospital_scoped', [1], 'key'));
    }

    public function testRejectsInvalidRecipientId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid recipient id 0');

        $this->publisher->publish(new NotificationRequest('test.in_app', [0], 'key'));
    }

    private function createUserId(): int
    {
        $id = UserFactory::createOne()->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function countNotifications(int $userId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$userId]);
    }

    /**
     * @return array{version: int, unread_count: int}|false
     */
    private function recipientState(int $userId): array|false
    {
        $row = $this->connection->fetchAssociative('SELECT version, unread_count FROM notification_recipient_state WHERE user_id = ?', [$userId]);

        return false === $row ? false : ['version' => (int) $row['version'], 'unread_count' => (int) $row['unread_count']];
    }
}
