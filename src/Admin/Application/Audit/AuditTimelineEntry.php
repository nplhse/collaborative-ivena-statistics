<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * One audit entry as the timeline shows it. Holds the names of changed fields, never their values.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the audit timeline templates.
 */
final readonly class AuditTimelineEntry
{
    /**
     * @param list<string> $changedFields
     */
    public function __construct(
        public int $id,
        public \DateTimeImmutable $occurredAt,
        public string $requestId,
        public bool $legacyRequestId,
        public ?int $actorId,
        public ?string $actorName,
        public string $origin,
        public string $action,
        public string $entityClass,
        public ?string $entityId,
        public ?string $intent,
        public ?string $outcome,
        public ?string $sessionRef,
        public array $changedFields,
    ) {
    }

    /**
     * Class name without namespace: `Hospital` for `App\Allocation\Domain\Entity\Hospital`.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the audit timeline templates.
     */
    public function entityName(): string
    {
        $pos = strrpos($this->entityClass, '\\');

        return false === $pos ? $this->entityClass : substr($this->entityClass, $pos + 1);
    }

    /**
     * Ascending timeline order: occurredAt, then id for entries written in the same second.
     */
    public static function compareAscending(self $a, self $b): int
    {
        return [$a->occurredAt, $a->id] <=> [$b->occurredAt, $b->id];
    }
}
