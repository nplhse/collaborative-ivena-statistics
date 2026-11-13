<?php

declare(strict_types=1);

namespace App\Import\Application\Message;

use App\Import\Domain\Enum\ImportType;

/**
 * Import processing message that belongs to one ImportRun.
 */
interface ImportRunMessageInterface
{
    public function getImportId(): int;

    /** Null for legacy messages dispatched before import runs existed. */
    public function getRunId(): ?string;

    /** Type of the import the message processes; names the job of the run when Messenger gives up (ADR 018). */
    public function getImportType(): ImportType;
}
