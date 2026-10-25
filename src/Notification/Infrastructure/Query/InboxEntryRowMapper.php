<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Query;

use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Domain\Enum\NotificationAccessScope;

/**
 * Maps a `notification` row onto an inbox entry. Shared by the inbox query and the toast claim.
 */
final class InboxEntryRowMapper
{
    public const string COLUMNS = 'id, translation_domain, title_key, message_key, parameters, action_route, action_parameters,
        access_scope, scope_reference_id, required_role, created_at, read_at';

    /**
     * @param array<string, mixed> $row
     */
    public static function map(array $row): InboxEntry
    {
        return new InboxEntry(
            id: (int) $row['id'],
            translationDomain: (string) $row['translation_domain'],
            titleKey: (string) $row['title_key'],
            messageKey: (string) $row['message_key'],
            parameters: self::scalarMap($row['parameters']),
            actionRoute: null === $row['action_route'] ? null : (string) $row['action_route'],
            actionParameters: array_filter(self::scalarMap($row['action_parameters']), static fn (mixed $value): bool => null !== $value),
            accessScope: NotificationAccessScope::from((string) $row['access_scope']),
            scopeReferenceId: null === $row['scope_reference_id'] ? null : (int) $row['scope_reference_id'],
            requiredRole: null === $row['required_role'] ? null : (string) $row['required_role'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            readAt: null === $row['read_at'] ? null : new \DateTimeImmutable((string) $row['read_at']),
        );
    }

    /**
     * Decodes a stored JSON object and keeps only string keys with scalar or null values.
     *
     * @return array<string, scalar|null>
     */
    private static function scalarMap(mixed $json): array
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
