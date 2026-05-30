<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

final readonly class ImportRequeueItemResult
{
    public function __construct(
        public int $importId,
        public ?string $name,
        public ?string $filePath,
        public string $consoleStatus,
    ) {
    }
}
