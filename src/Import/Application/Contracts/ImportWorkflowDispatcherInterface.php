<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportRunTrigger;

interface ImportWorkflowDispatcherInterface
{
    /**
     * Registers a new run for the import (superseding an unfinished previous run) and queues its message,
     * in one transaction.
     *
     * @param ImportRunTrigger        $trigger           where the run came from
     * @param int|null                $initiatedByUserId user who deliberately started the run, if any
     * @param ImportBatchRunItem|null $batchRunItem      requeue batch item the run belongs to
     */
    public function dispatch(int $importId, ImportRunTrigger $trigger, ?int $initiatedByUserId = null, ?ImportBatchRunItem $batchRunItem = null): void;
}
