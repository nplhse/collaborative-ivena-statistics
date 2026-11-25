<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

/**
 * One field that differs between two versions of a data extract request. Text fields carry a line diff; dates and
 * yes/no fields only the old and the new value.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the template.
 */
final readonly class FieldChange
{
    public const string TEXT = 'text';
    public const string DATE = 'date';
    public const string FLAG = 'flag';

    /**
     * @param list<array{type: 'same'|'added'|'removed', text: string}> $lines
     */
    public function __construct(
        public string $field,
        public string $labelKey,
        public string $kind,
        public ?string $before,
        public ?string $after,
        public array $lines = [],
    ) {
    }
}
