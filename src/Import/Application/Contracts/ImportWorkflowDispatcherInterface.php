<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Domain\Entity\ImportBatchRunItem;

interface ImportWorkflowDispatcherInterface
{
    /**
     * Registers a new run for the import (superseding an unfinished previous run) and queues its message.
     *
     * @param int|null                $initiatedByUserId user who deliberately started the run, if any
     * @param ImportBatchRunItem|null $batchRunItem      requeue batch item the run belongs to
     */
    public function dispatch(int $importId, ?int $initiatedByUserId = null, ?ImportBatchRunItem $batchRunItem = null): void;
}
