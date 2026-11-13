<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

final readonly class ImportIssue
{
    public function __construct(
        public int $importId,
        public ImportIssueKind $kind,
        public \DateTimeImmutable $uploadedAt,
        public int $amount,
    ) {
    }
}
