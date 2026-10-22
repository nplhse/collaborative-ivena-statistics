<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Query;

use App\Notification\Application\Contract\NotificationInboxQueryInterface;
use App\Notification\Application\Inbox\InboxCursor;
use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Application\Inbox\InboxPage;
use App\Notification\Application\Inbox\RecipientInboxState;
use App\Notification\Domain\Enum\NotificationAccessScope;
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
    private const string COLUMNS = 'id, translation_domain, title_key, message_key, parameters, action_route, action_parameters,
        access_scope, scope_reference_id, required_role, created_at, read_at';

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
    public function latest(int $recipientId, int $limit = InboxPage::DROPDOWN_SIZE): array
    {
        return $this->fetch(
            \sprintf('SELECT %s FROM notification WHERE %s ORDER BY created_at DESC, id DESC LIMIT %d', self::COLUMNS, self::VISIBLE, $limit),
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
                self::COLUMNS,
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
            \sprintf('SELECT %s FROM notification WHERE %s AND id = :id', self::COLUMNS, self::VISIBLE),
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
        $entries = [];

        foreach ($this->connection->fetchAllAssociative($sql, $parameters, $types) as $row) {
            $entries[] = new InboxEntry(
                id: (int) $row['id'],
                translationDomain: (string) $row['translation_domain'],
                titleKey: (string) $row['title_key'],
                messageKey: (string) $row['message_key'],
                parameters: $this->scalarMap($row['parameters']),
                actionRoute: null === $row['action_route'] ? null : (string) $row['action_route'],
                actionParameters: array_filter($this->scalarMap($row['action_parameters']), static fn (mixed $value): bool => null !== $value),
                accessScope: NotificationAccessScope::from((string) $row['access_scope']),
                scopeReferenceId: null === $row['scope_reference_id'] ? null : (int) $row['scope_reference_id'],
                requiredRole: null === $row['required_role'] ? null : (string) $row['required_role'],
                createdAt: new \DateTimeImmutable((string) $row['created_at']),
                readAt: null === $row['read_at'] ? null : new \DateTimeImmutable((string) $row['read_at']),
            );
        }

        return $entries;
    }

    /**
     * Decodes a stored JSON object and keeps only string keys with scalar or null values.
     *
     * @return array<string, scalar|null>
     */
    private function scalarMap(mixed $json): array
    {
        $decoded = json_decode((string) $json, true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key) && (null === $value || \is_scalar($value))) {
                $map[$key] = $value;
            }
        }

        return $map;
    }
}
