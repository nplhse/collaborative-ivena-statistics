<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Retention;

use App\Notification\Application\Contract\NotificationRetentionInterface;
use App\Notification\Application\Retention\NotificationRetentionResult;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Retention per ADR 016: read entries after 30 days, unread entries after 90 days, removed entries
 * at the next run, expired deduplication keys. Then unread_count is recounted with the same visibility
 * rules as the inbox (redacted entries count, role-scoped entries only with the required role).
 *
 * An instance is only deleted when all its deliveries reached `sent` or `failed` at least 30 days ago.
 * Instances without inbox entry (external channels only) are deleted by that rule alone; removing an
 * entry from the inbox never cancels its deliveries.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for NotificationRetentionInterface.
 */
#[AsAlias(NotificationRetentionInterface::class)]
final readonly class DbalNotificationRetention implements NotificationRetentionInterface
{
    public const string READ_RETENTION = 'P30D';

    public const string UNREAD_RETENTION = 'P90D';

    public const string DELIVERY_RETENTION = 'P30D';

    public function __construct(
        private Connection $connection,
        private UserRepository $userRepository,
        private RoleHierarchyInterface $roleHierarchy,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function run(): NotificationRetentionResult
    {
        $now = $this->clock->now();

        $deletedNotifications = $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM notification n
                WHERE (n.in_app_visible = false
                       OR n.removed_at IS NOT NULL
                       OR (n.read_at IS NOT NULL AND n.read_at < :read_before)
                       OR (n.read_at IS NULL AND n.created_at < :unread_before))
                  AND NOT EXISTS (
                      SELECT 1 FROM notification_delivery d
                      WHERE d.notification_id = n.id
                        AND (d.status NOT IN ('sent', 'failed') OR d.status_changed_at >= :delivery_before)
                  )
                SQL,
            [
                'read_before' => $now->sub(new \DateInterval(self::READ_RETENTION)),
                'unread_before' => $now->sub(new \DateInterval(self::UNREAD_RETENTION)),
                'delivery_before' => $now->sub(new \DateInterval(self::DELIVERY_RETENTION)),
            ],
            ['read_before' => Types::DATETIME_IMMUTABLE, 'unread_before' => Types::DATETIME_IMMUTABLE, 'delivery_before' => Types::DATETIME_IMMUTABLE],
        );

        $deletedKeys = $this->connection->executeStatement(
            'DELETE FROM notification_deduplication WHERE expires_at < :now',
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return new NotificationRetentionResult($deletedNotifications, $deletedKeys, $this->recount($now));
    }

    /**
     * @return int number of recipients whose counter was corrected
     */
    private function recount(\DateTimeImmutable $now): int
    {
        $expected = [];
        foreach ($this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT recipient_id, COUNT(*) AS unread
                FROM notification
                WHERE read_at IS NULL AND removed_at IS NULL AND in_app_visible = true AND access_scope <> 'role'
                GROUP BY recipient_id
                SQL,
        ) as $row) {
            $expected[(int) $row['recipient_id']] = (int) $row['unread'];
        }

        $roleScoped = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT recipient_id, required_role, COUNT(*) AS unread
                FROM notification
                WHERE read_at IS NULL AND removed_at IS NULL AND in_app_visible = true AND access_scope = 'role'
                GROUP BY recipient_id, required_role
                SQL,
        );
        $reachableRoles = $this->reachableRoles(array_map(static fn (array $row): int => (int) $row['recipient_id'], $roleScoped));
        foreach ($roleScoped as $row) {
            $recipientId = (int) $row['recipient_id'];
            if (\in_array($row['required_role'], $reachableRoles[$recipientId] ?? [], true)) {
                $expected[$recipientId] = ($expected[$recipientId] ?? 0) + (int) $row['unread'];
            }
        }

        $corrected = 0;
        foreach ($this->connection->fetchAllAssociative('SELECT user_id, unread_count FROM notification_recipient_state') as $state) {
            $userId = (int) $state['user_id'];
            $count = $expected[$userId] ?? 0;
            if ($count === (int) $state['unread_count']) {
                continue;
            }

            $corrected += $this->connection->executeStatement(
                'UPDATE notification_recipient_state SET unread_count = :count, version = version + 1, updated_at = :now WHERE user_id = :user',
                ['count' => $count, 'now' => $now, 'user' => $userId],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
        }

        return $corrected;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, list<string>>
     */
    private function reachableRoles(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $roles = [];
        foreach ($this->userRepository->findBy(['id' => array_values(array_unique($userIds))]) as $user) {
            $id = $user instanceof User ? $user->getId() : null;
            if (null !== $id) {
                $roles[$id] = $this->roleHierarchy->getReachableRoleNames($user->getRoles());
            }
        }

        return $roles;
    }
}
