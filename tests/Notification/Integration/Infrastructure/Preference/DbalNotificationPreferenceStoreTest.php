<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Preference;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Notification\Infrastructure\Preference\DbalNotificationPreferenceStore;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\Type\FixedTestNotification;
use App\Tests\Support\Notification\Type\ToastTestNotification;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class DbalNotificationPreferenceStoreTest extends DatabaseKernelTestCase
{
    private Connection $connection;
    private DbalNotificationPreferenceStore $store;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->store = new DbalNotificationPreferenceStore($this->connection);
    }

    public function testSavesUpdatesAndRemovesOverrides(): void
    {
        $userId = $this->createUserId();

        $this->store->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'imports', NotificationChannel::Email, true),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Toast, false),
        ]);

        $set = $this->store->load($userId);
        self::assertTrue($set->get(NotificationPreferenceScope::Category, 'imports', NotificationChannel::Email));
        self::assertFalse($set->get(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Toast));
        self::assertNull($set->get(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Email));

        $this->store->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'imports', NotificationChannel::Email, false),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Toast, null),
        ]);

        $set = $this->store->load($userId);
        self::assertFalse($set->get(NotificationPreferenceScope::Category, 'imports', NotificationChannel::Email));
        self::assertNull($set->get(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Toast));
        self::assertSame(1, $this->countRows($userId));
    }

    public function testRemovingAMissingOverrideAndSavingNothingAreNoOps(): void
    {
        $userId = $this->createUserId();

        $this->store->save($userId, []);
        $this->store->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'import.failed', NotificationChannel::Email, null),
        ]);

        self::assertSame(0, $this->countRows($userId));
    }

    public function testOverridesForReadsOnlyTheTypeAndItsCategory(): void
    {
        $userId = $this->createUserId();
        $otherId = $this->createUserId();
        $this->store->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::InApp, false),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::Toast, true),
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'imports', NotificationChannel::Toast, false),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.other', NotificationChannel::InApp, true),
        ]);
        $this->store->save($otherId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::InApp, true),
        ]);

        $overrides = $this->store->overridesFor($userId, $this->definition(ToastTestNotification::class));

        self::assertFalse($overrides->forCategory(NotificationChannel::InApp));
        self::assertNull($overrides->forCategory(NotificationChannel::Toast));
        self::assertTrue($overrides->forType(NotificationChannel::Toast));
        self::assertNull($overrides->forType(NotificationChannel::InApp));
    }

    public function testNonConfigurableTypesHaveNoOverrides(): void
    {
        $userId = $this->createUserId();
        $this->store->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.fixed', NotificationChannel::InApp, false),
        ]);

        $overrides = $this->store->overridesFor($userId, $this->definition(FixedTestNotification::class));

        self::assertNull($overrides->forType(NotificationChannel::InApp));
    }

    public function testRowsOfUnknownChannelsAreSkipped(): void
    {
        $userId = $this->createUserId();
        $this->connection->executeStatement(
            "INSERT INTO user_notification_preference (user_id, scope, preference_key, channel, enabled) VALUES (?, 'type', 'test.toast', 'sms', true)",
            [$userId],
        );

        $overrides = $this->store->overridesFor($userId, $this->definition(ToastTestNotification::class));

        foreach (NotificationChannel::cases() as $channel) {
            self::assertNull($overrides->forType($channel));
        }
    }

    public function testOverridesAreDeletedWithTheUser(): void
    {
        $user = UserFactory::createOne();
        $userId = (int) $user->getId();
        $this->store->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'imports', NotificationChannel::InApp, false),
        ]);

        $this->connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]);

        self::assertSame(0, $this->countRows($userId));
    }

    /**
     * @param class-string $class
     */
    private function definition(string $class): NotificationDefinition
    {
        $definition = NotificationRegistry::readDefinition($class);
        self::assertNotNull($definition);

        return $definition;
    }

    private function createUserId(): int
    {
        $id = UserFactory::createOne()->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function countRows(int $userId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_notification_preference WHERE user_id = ?', [$userId]);
    }
}
