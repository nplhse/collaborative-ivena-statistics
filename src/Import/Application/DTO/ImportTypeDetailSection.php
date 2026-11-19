<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * Template and context of the section an import type extension adds to the import detail page, and the links to
 * the import's records it adds to the actions.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by the import detail template.
 */
final readonly class ImportTypeDetailSection
{
    /**
     * @param array<string, mixed>         $context
     * @param list<ImportTypeRecordAction> $recordActions
     */
    public function __construct(
        public string $template,
        public array $context = [],
        public array $recordActions = [],
    ) {
    }
}
