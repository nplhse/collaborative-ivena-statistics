<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Application\DTO\ImportBulkDeletionSummary;

/**
 * Announces the outcome of a background bulk deletion to the user who started it.
 */
interface ImportBulkDeletionPublisherInterface
{
    public function publish(ImportBulkDeletionSummary $summary): void;
}
