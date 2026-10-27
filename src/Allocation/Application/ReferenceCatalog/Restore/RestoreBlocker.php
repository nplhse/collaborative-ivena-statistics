<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Restore;

/**
 * Rows that would lose their catalog reference, or be deleted by a cascade, if the catalog were replaced.
 */
final readonly class RestoreBlocker
{
    public function __construct(
        public string $table,
        public string $kind,
        public int $rows,
    ) {
    }
}
