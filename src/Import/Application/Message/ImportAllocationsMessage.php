<?php

declare(strict_types=1);

namespace App\Import\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/** @psalm-suppress PossiblyUnusedProperty initiatedByUserId and batchRunId are read by notification and batch handling (ADR 016) */
#[AsMessage('async_priority_high')]
final readonly class ImportAllocationsMessage implements ImportRunMessageInterface
{
    /**
     * @param string|null $runId Null only for messages queued before import runs existed (legacy)
     */
    public function __construct(
        public int $importId,
        public ?string $runId = null,
        public ?int $initiatedByUserId = null,
        public ?int $batchRunId = null,
    ) {
    }

    #[\Override]
    public function getImportId(): int
    {
        return $this->importId;
    }

    #[\Override]
    public function getRunId(): ?string
    {
        return $this->runId;
    }
}
