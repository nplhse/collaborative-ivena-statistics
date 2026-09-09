<?php

declare(strict_types=1);

namespace App\Import\Application\Repair;

use App\Import\Domain\Enum\ImportSourceFileStatus;

final readonly class QuoteBrokenImportCandidate
{
    public function __construct(
        public int $importId,
        public ?string $importName,
        public ?string $hospitalName,
        public ?string $filePath,
        public int $rejectCount,
        public ImportSourceFileStatus $sourceStatus,
    ) {
    }

    public function isReady(): bool
    {
        return $this->sourceStatus->isReady();
    }
}
