<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter;

/** @psalm-suppress PossiblyUnusedProperty Consumed by the import detail template. */
final readonly class ImportDetailView
{
    /**
     * @param list<ImportDetailAction> $recordActions
     */
    public function __construct(
        public string $typeLabel,
        public string $importedRecordsLabel,
        public bool $showsDeduplication,
        public string $deleteConfirmation,
        public array $recordActions,
    ) {
    }
}
