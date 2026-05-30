<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use App\Import\Application\ImportDispatchExitCode;

final readonly class ImportRequeueBatchSummary
{
    /**
     * @param list<ImportRequeueItemResult> $results
     */
    public function __construct(
        public int $exitCode,
        public int $dispatched = 0,
        public int $failed = 0,
        public int $skipped = 0,
        public int $wouldDispatch = 0,
        public ?int $runId = null,
        public bool $interrupted = false,
        public bool $maxRetriesExceeded = false,
        public array $results = [],
    ) {
    }

    public static function empty(int $exitCode = ImportDispatchExitCode::SUCCESS): self
    {
        return new self(exitCode: $exitCode);
    }
}
