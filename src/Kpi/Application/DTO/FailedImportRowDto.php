<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

use App\Import\Domain\Enum\ImportStatus;

final readonly class FailedImportRowDto
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public string $hospitalName,
        public string $fileName,
        public ImportStatus $status,
        public string $failureReasonKey,
        public int $recordCount,
        public int $rejectionCount,
        public ?string $detailUrl,
    ) {
    }
}
