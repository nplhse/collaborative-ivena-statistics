<?php

declare(strict_types=1);

namespace App\Import\Application\Message;

/**
 * Import processing message that belongs to one ImportRun.
 */
interface ImportRunMessageInterface
{
    public function getImportId(): int;

    /** Null for legacy messages dispatched before import runs existed. */
    public function getRunId(): ?string;
}
