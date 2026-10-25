<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Application\DTO\ImportBatchSummary;

/**
 * Announces a finished batch re-import. Called inside the transaction that finishes the batch, so the
 * implementation must use the default connection and must not commit or roll back on its own.
 */
interface ImportBatchSummaryPublisherInterface
{
    public function publish(ImportBatchSummary $summary): void;
}
