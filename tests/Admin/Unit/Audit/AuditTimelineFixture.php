<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Audit;

use App\Admin\Application\Audit\AuditTimelineEntry;

/**
 * Small builder for timeline entries with readable defaults.
 */
final class AuditTimelineFixture
{
    public static function entry(
        int $id,
        string $at,
        string $requestId,
        ?int $actorId = 1,
        string $origin = 'http',
        bool $legacy = false,
        ?string $intent = null,
    ): AuditTimelineEntry {
        return new AuditTimelineEntry(
            id: $id,
            occurredAt: new \DateTimeImmutable('2026-11-13 '.$at),
            requestId: $requestId,
            legacyRequestId: $legacy,
            actorId: $actorId,
            actorName: null === $actorId ? null : 'user'.$actorId,
            origin: $origin,
            action: 'update',
            entityClass: 'App\Example\Thing',
            entityId: (string) $id,
            intent: $intent,
            outcome: null,
            sessionRef: null,
            changedFields: [],
        );
    }
}
