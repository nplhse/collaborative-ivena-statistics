<?php

declare(strict_types=1);

namespace App\Import\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogDocument;

final readonly class ReferenceCatalogFromRejectsResult
{
    /**
     * @param list<int>                                                                  $importIds
     * @param list<array{importId: int, hospitalName: string, file: string, count: int}> $imports
     * @param list<array{type: string, value: string, count: int, exampleFile: string}>  $entries
     */
    public function __construct(
        public ReferenceCatalogDocument $document,
        public array $importIds,
        public array $imports,
        public array $entries,
        public int $rejectRowsScanned,
        public int $proposedCount,
        public int $droppedAsKnown,
        public int $droppedAsJunk,
    ) {
    }
}
