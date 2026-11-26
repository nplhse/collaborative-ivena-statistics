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
     * @param int|null                     $importedRecordCount records the import actually added, when the type
     *                                                          counts them differently from the rows that passed
     *                                                          (#1463)
     */
    public function __construct(
        public string $template,
        public array $context = [],
        public array $recordActions = [],
        public ?int $importedRecordCount = null,
    ) {
    }
}
