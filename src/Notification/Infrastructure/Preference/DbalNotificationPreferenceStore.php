<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Preference;

use App\Notification\Application\Contract\NotificationPreferenceStoreInterface;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Application\Preference\NotificationPreferenceOverrides;
use App\Notification\Application\Preference\NotificationPreferenceSet;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Stores preference overrides in user_notification_preference with plain DBAL statements,
 * so that the publisher can read them inside its own transaction without the entity manager.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for NotificationPreferenceStoreInterface.
 */
#[AsAlias(NotificationPreferenceStoreInterface::class)]
final readonly class DbalNotificationPreferenceStore implements NotificationPreferenceStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function overridesFor(int $userId, NotificationDefinition $definition): NotificationPreferenceOverrides
    {
        if (!$definition->configurable) {
            return NotificationPreferenceOverrides::none();
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT scope, preference_key, channel, enabled
                FROM user_notification_preference
                WHERE user_id = :user
                  AND ((scope = :type_scope AND preference_key = :type) OR (scope = :category_scope AND preference_key = :category))
                SQL,
            [
                'user' => $userId,
                'type_scope' => NotificationPreferenceScope::Type->value,
                'type' => $definition->type,
                'category_scope' => NotificationPreferenceScope::Category->value,
                'category' => $definition->category->value,
            ],
        );

        return NotificationPreferenceSet::fromOverrides($this->map($rows))->forDefinition($definition);
    }

    #[\Override]
    public function load(int $userId): NotificationPreferenceSet
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT scope, preference_key, channel, enabled FROM user_notification_preference WHERE user_id = :user',
            ['user' => $userId],
        );

        return NotificationPreferenceSet::fromOverrides($this->map($rows));
    }

    #[\Override]
    public function save(int $userId, array $changes): void
    {
        if ([] === $changes) {
            return;
        }

        $this->connection->transactional(static function (Connection $connection) use ($userId, $changes): void {
            foreach ($changes as $change) {
                $key = [
                    'user' => $userId,
                    'scope' => $change->scope->value,
                    'preference_key' => $change->key,
                    'channel' => $change->channel->value,
                ];

                if (null === $change->enabled) {
                    $connection->executeStatement(
                        'DELETE FROM user_notification_preference WHERE user_id = :user AND scope = :scope AND preference_key = :preference_key AND channel = :channel',
                        $key,
                    );

                    continue;
                }

                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO user_notification_preference (user_id, scope, preference_key, channel, enabled)
                        VALUES (:user, :scope, :preference_key, :channel, :enabled)
                        ON CONFLICT (user_id, scope, preference_key, channel) DO UPDATE SET enabled = EXCLUDED.enabled
                        SQL,
                    [...$key, 'enabled' => $change->enabled],
                    ['enabled' => Types::BOOLEAN],
                );
            }
        });
    }

    /**
     * Rows of channels that no longer exist are skipped.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return \Generator<NotificationPreferenceChange>
     */
    private function map(array $rows): \Generator
    {
        foreach ($rows as $row) {
            $scope = NotificationPreferenceScope::tryFrom((string) $row['scope']);
            $channel = NotificationChannel::tryFrom((string) $row['channel']);
            if (null === $scope || null === $channel) {
                continue;
            }

            yield new NotificationPreferenceChange($scope, (string) $row['preference_key'], $channel, (bool) $row['enabled']);
        }
    }
}
