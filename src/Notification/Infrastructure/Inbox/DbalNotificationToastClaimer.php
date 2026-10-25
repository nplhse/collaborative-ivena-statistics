<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Inbox;

use App\Notification\Application\Contract\NotificationToastClaimerInterface;
use App\Notification\Application\Inbox\ClaimedToast;
use App\Notification\Domain\Enum\NotificationPriority;
use App\Notification\Infrastructure\Query\InboxEntryRowMapper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Claims pending toasts with one conditional update (ADR 016, "Toast delivery and active sessions").
 *
 * Concurrent polls of the same user skip rows another poll has locked, and the update only touches
 * rows that are still unclaimed, so every toast is handed out at most once. Entries that were read
 * or removed in the meantime are not toasted any more.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for NotificationToastClaimerInterface.
 */
#[AsAlias(NotificationToastClaimerInterface::class)]
final readonly class DbalNotificationToastClaimer implements NotificationToastClaimerInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<ClaimedToast>
     */
    #[\Override]
    public function claim(int $recipientId, \DateTimeImmutable $now, int $limit): array
    {
        $columns = implode(', ', array_map(
            static fn (string $column): string => 'n.'.trim($column),
            explode(',', InboxEntryRowMapper::COLUMNS),
        ));

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'WITH claimable AS (
                    SELECT id FROM notification
                    WHERE recipient_id = :recipient
                      AND toast_claimed_at IS NULL
                      AND toast_pending_until > :now
                      AND in_app_visible = true
                      AND removed_at IS NULL
                      AND read_at IS NULL
                    ORDER BY created_at, id
                    LIMIT %d
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE notification n SET toast_claimed_at = :now
                FROM claimable
                WHERE n.id = claimable.id AND n.toast_claimed_at IS NULL
                RETURNING %s, n.priority',
                max(1, $limit),
                $columns,
            ),
            ['recipient' => $recipientId, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        // RETURNING does not keep the order of the CTE.
        usort($rows, static fn (array $a, array $b): int => [(string) $a['created_at'], (int) $a['id']] <=> [(string) $b['created_at'], (int) $b['id']]);

        return array_map(
            static fn (array $row): ClaimedToast => new ClaimedToast(
                InboxEntryRowMapper::map($row),
                NotificationPriority::from((string) $row['priority']),
            ),
            $rows,
        );
    }
}
