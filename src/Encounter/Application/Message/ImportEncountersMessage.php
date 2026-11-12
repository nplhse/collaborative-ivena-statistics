<?php

declare(strict_types=1);

namespace App\Encounter\Application\Message;

use App\Import\Application\Message\ImportRunMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Processes one run of an encounter import (#1048). Always belongs to an import run.
 *
 * @psalm-suppress PossiblyUnusedProperty initiatedByUserId is kept for notifications like the other import messages
 */
#[AsMessage('async_priority_high')]
final readonly class ImportEncountersMessage implements ImportRunMessageInterface
{
    public function __construct(
        public int $importId,
        public string $runId,
        public ?int $initiatedByUserId = null,
    ) {
    }

    #[\Override]
    public function getImportId(): int
    {
        return $this->importId;
    }

    #[\Override]
    public function getRunId(): string
    {
        return $this->runId;
    }
}
