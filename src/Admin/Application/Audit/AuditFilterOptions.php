<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Values that occur in the audit log, offered as choices in the timeline filter.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the audit timeline template.
 */
final readonly class AuditFilterOptions
{
    /**
     * @param list<string>                            $intents
     * @param list<string>                            $entityClasses
     * @param list<array{id: int, name: string|null}> $actors        name is null for deleted users
     */
    public function __construct(
        public array $intents,
        public array $entityClasses,
        public array $actors,
    ) {
    }
}
