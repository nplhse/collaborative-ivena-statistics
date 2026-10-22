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
    public function page(int $recipientId, ?InboxCursor $cursor, int $size = InboxPage::OVERVIEW_PAGE_SIZE): InboxPage
    {
        $parameters = ['recipient' => $recipientId];
        $types = [];
        $after = '';
        if ($cursor instanceof InboxCursor) {
            $after = ' AND (created_at, id) < (:cursor_created_at, :cursor_id)';
            $parameters['cursor_created_at'] = $cursor->createdAt;
            $parameters['cursor_id'] = $cursor->id;
            $types['cursor_created_at'] = Types::DATETIME_IMMUTABLE;
        }

        $entries = $this->fetch(
            \sprintf('SELECT %s FROM notification WHERE %s%s ORDER BY created_at DESC, id DESC LIMIT %d', self::COLUMNS, self::VISIBLE, $after, $size + 1),
            $parameters,
            $types,
        );

        if (\count($entries) <= $size) {
            return new InboxPage($entries, null);
        }

        $entries = \array_slice($entries, 0, $size);

        return new InboxPage($entries, InboxCursor::after($entries[$size - 1]));
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
