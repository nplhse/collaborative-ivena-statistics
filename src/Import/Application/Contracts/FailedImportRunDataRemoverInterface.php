<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Application\DTO\ImportDataRemoval;
use App\Import\Domain\Entity\Import;

/**
 * Removes what a failed run wrote. The import record, its counts, rejected rows and files stay for analysis and
 * reprocessing.
 */
interface FailedImportRunDataRemoverInterface
{
    /**
     * Runs inside the transaction that ends the run.
     */
    public function removeRowsOfFailedRun(Import $import): ImportDataRemoval;

    /**
     * Rebuild requests and view refresh; runs after that transaction has committed.
     *
     * @param bool $previousRunRemoved the run replaced the rows of an earlier run before it failed
     */
    public function completeFailedRunRemoval(Import $import, ImportDataRemoval $removal, bool $previousRunRemoved): void;
}
