<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter;

use App\Import\UI\Http\Presenter\Coverage\ImportCoverageView;

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
        public string $reprocessConfirmation,
        public array $recordActions,
        public ImportCoverageView $coverage,
    ) {
    }
}
