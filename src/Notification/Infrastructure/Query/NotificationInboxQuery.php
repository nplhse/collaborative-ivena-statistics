<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Query;

use App\Notification\Application\Contract\NotificationInboxQueryInterface;
use App\Notification\Application\Inbox\InboxCursor;
use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Application\Inbox\InboxPage;
use App\Notification\Application\Inbox\RecipientInboxState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reads the inbox of one recipient: visible, not removed entries, newest first (keyset on created_at, id).
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for NotificationInboxQueryInterface.
 */
#[AsAlias(NotificationInboxQueryInterface::class)]
final readonly class NotificationInboxQuery implements NotificationInboxQueryInterface
{
    private const string VISIBLE = 'recipient_id = :recipient AND in_app_visible = true AND removed_at IS NULL';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function state(int $recipientId): RecipientInboxState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT version, unread_count FROM notification_recipient_state WHERE user_id = :recipient',
            ['recipient' => $recipientId],
        );

        return false === $row
            ? new RecipientInboxState()
            : new RecipientInboxState((int) $row['version'], max(0, (int) $row['unread_count']));
    }

    /**
     * @return list<InboxEntry>
     */
    #[\Override]
    public function latestUnread(int $recipientId, int $limit = InboxPage::DROPDOWN_SIZE): array
    {
        return $this->fetch(
            \sprintf('SELECT %s FROM notification WHERE %s AND read_at IS NULL ORDER BY created_at DESC, id DESC LIMIT %d', InboxEntryRowMapper::COLUMNS, self::VISIBLE, $limit),
            ['recipient' => $recipientId],
        );
    }

    #[\Override]
    public function count(int $recipientId): int
    {
        return (int) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM notification WHERE %s', self::VISIBLE),
            ['recipient' => $recipientId],
        );
    }

    #[\Override]
    public function page(
        int $recipientId,
        ?InboxCursor $before = null,
        ?InboxCursor $after = null,
        int $size = InboxPage::OVERVIEW_PAGE_SIZE,
        bool $unreadOnly = false,
    ): InboxPage {
        $filter = $unreadOnly ? ' AND read_at IS NULL' : '';

        if (!$before instanceof InboxCursor && $after instanceof InboxCursor) {
            // Walk towards newer entries, then restore the newest-first order.
            $entries = $this->fetchFrom($recipientId, $filter, '>', $after, 'ASC', $size + 1);
            $hasNewer = \count($entries) > $size;
            $entries = array_reverse(\array_slice($entries, 0, $size));
            $hasOlder = [] !== $entries && $this->exists($recipientId, $filter, '<', InboxCursor::at($entries[\count($entries) - 1]));
        } else {
            $entries = $this->fetchFrom($recipientId, $filter, '<', $before, 'DESC', $size + 1);
            $hasOlder = \count($entries) > $size;
            $entries = \array_slice($entries, 0, $size);
            $hasNewer = [] !== $entries && $before instanceof InboxCursor && $this->exists($recipientId, $filter, '>', InboxCursor::at($entries[0]));
        }

        if ([] === $entries) {
            return new InboxPage([]);
        }

        return new InboxPage(
            $entries,
            $hasOlder ? InboxCursor::at($entries[\count($entries) - 1]) : null,
            $hasNewer ? InboxCursor::at($entries[0]) : null,
        );
    }

    /**
     * @param '<'|'>'      $comparison
     * @param 'ASC'|'DESC' $direction
     *
     * @return list<InboxEntry>
     */
    private function fetchFrom(int $recipientId, string $filter, string $comparison, ?InboxCursor $cursor, string $direction, int $limit): array
    {
        [$condition, $parameters, $types] = $this->cursorCondition($recipientId, $comparison, $cursor);

        return $this->fetch(
            \sprintf(
                'SELECT %s FROM notification WHERE %s%s%s ORDER BY created_at %s, id %s LIMIT %d',
                InboxEntryRowMapper::COLUMNS,
                self::VISIBLE,
                $filter,
                $condition,
                $direction,
                $direction,
                $limit,
            ),
            $parameters,
            $types,
        );
    }

    /**
     * @param '<'|'>' $comparison
     */
    private function exists(int $recipientId, string $filter, string $comparison, InboxCursor $cursor): bool
    {
        [$condition, $parameters, $types] = $this->cursorCondition($recipientId, $comparison, $cursor);

        return false !== $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM notification WHERE %s%s%s LIMIT 1', self::VISIBLE, $filter, $condition),
            $parameters,
            $types,
        );
    }

    /**
     * @param '<'|'>' $comparison
     *
     * @return array{string, array<string, mixed>, array<string, string>}
     */
    private function cursorCondition(int $recipientId, string $comparison, ?InboxCursor $cursor): array
    {
        if (!$cursor instanceof InboxCursor) {
            return ['', ['recipient' => $recipientId], []];
        }

        return [
            \sprintf(' AND (created_at, id) %s (:cursor_created_at, :cursor_id)', $comparison),
            ['recipient' => $recipientId, 'cursor_created_at' => $cursor->createdAt, 'cursor_id' => $cursor->id],
            ['cursor_created_at' => Types::DATETIME_IMMUTABLE],
        ];
    }

    #[\Override]
    public function find(int $recipientId, int $notificationId): ?InboxEntry
    {
        return $this->fetch(
            \sprintf('SELECT %s FROM notification WHERE %s AND id = :id', InboxEntryRowMapper::COLUMNS, self::VISIBLE),
            ['recipient' => $recipientId, 'id' => $notificationId],
        )[0] ?? null;
    }

    /**
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $types
     *
     * @return list<InboxEntry>
     */
    private function fetch(string $sql, array $parameters, array $types = []): array
    {
        return array_map(InboxEntryRowMapper::map(...), $this->connection->fetchAllAssociative($sql, $parameters, $types));
    }
}
