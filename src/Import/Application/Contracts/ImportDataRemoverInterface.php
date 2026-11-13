<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Removes data that another context stored for an import (for example encounters, ADR 029).
 *
 * Called by ImportRelatedDataCleanupService inside the transaction that removes the import's own rows,
 * both when an import is deleted and before an import is reprocessed. The context that owns the data
 * implements this, so Import does not depend on it.
 */
#[AutoconfigureTag(self::TAG)]
interface ImportDataRemoverInterface
{
    public const string TAG = 'import.data_remover';

    /**
     * Short snake_case name used in counts and log messages (`import.<key>.cleared`).
     */
    public function key(): string;

    /**
     * Deletes the rows created by the import and returns their number.
     */
    public function removeForImport(int $importId): int;
}
