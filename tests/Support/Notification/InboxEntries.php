<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification;

use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Domain\Enum\NotificationAccessScope;

/**
 * Builds InboxEntry objects for tests without a database.
 */
final class InboxEntries
{
    /**
     * @param array<string, scalar> $actionParameters
     */
    public static function entry(
        int $id,
        NotificationAccessScope $scope = NotificationAccessScope::None,
        ?int $hospitalId = null,
        ?string $role = null,
        ?string $route = null,
        array $actionParameters = [],
    ): InboxEntry {
        return new InboxEntry(
            id: $id,
            translationDomain: 'notification',
            titleKey: 'test.example.title',
            messageKey: 'test.example.message',
            parameters: ['name' => 'Klinikum'],
            actionRoute: $route,
            actionParameters: $actionParameters,
            accessScope: $scope,
            scopeReferenceId: $hospitalId,
            requiredRole: $role,
            createdAt: new \DateTimeImmutable('2026-10-22 12:00:00'),
            readAt: null,
        );
    }
}
