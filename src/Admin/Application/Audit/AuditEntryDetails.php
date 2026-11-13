<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * The expandable part of one audit entry: recorded changes and metadata, prepared as plain text.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the audit entry templates.
 */
final readonly class AuditEntryDetails
{
    /**
     * @param list<array{field: string, old: AuditDetailValue, new: AuditDetailValue}> $changes    field changes (old → new)
     * @param AuditDetailValue|null                                                    $rawChanges changes that are not an old/new map
     * @param list<array{key: string, value: AuditDetailValue}>                        $metadata
     */
    public function __construct(
        public AuditTimelineEntry $entry,
        public array $changes,
        public ?AuditDetailValue $rawChanges,
        public array $metadata,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null $changes  decoded `changes` column
     * @param array<array-key, mixed>|null $metadata decoded `metadata` column
     */
    public static function from(AuditTimelineEntry $entry, ?array $changes, ?array $metadata): self
    {
        $changes ??= [];
        $isOldNew = array_all($changes, static fn (mixed $delta): bool => \is_array($delta) && \array_key_exists('old', $delta) && \array_key_exists('new', $delta));

        $rows = [];
        if ($isOldNew) {
            foreach ($changes as $field => $delta) {
                /* @var array{old: mixed, new: mixed} $delta */
                $rows[] = ['field' => (string) $field, 'old' => AuditDetailValue::of($delta['old']), 'new' => AuditDetailValue::of($delta['new'])];
            }
        }

        $meta = [];
        foreach ($metadata ?? [] as $key => $value) {
            $meta[] = ['key' => (string) $key, 'value' => AuditDetailValue::of($value)];
        }

        return new self(
            $entry,
            $rows,
            $isOldNew || [] === $changes ? null : AuditDetailValue::of($changes),
            $meta,
        );
    }
}
