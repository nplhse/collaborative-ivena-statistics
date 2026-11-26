<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

final readonly class ImportIssue
{
    /**
     * @param list<int> $gapDays       {@see ImportIssueKind::DayGaps}: length in days of each gap inside the import's period
     * @param float     $recordsPerDay {@see ImportIssueKind::DayGaps}: the import's records divided by the days of its period
     */
    public function __construct(
        public int $importId,
        public ImportIssueKind $kind,
        public \DateTimeImmutable $uploadedAt,
        public int $amount,
        public array $gapDays = [],
        public float $recordsPerDay = 0.0,
    ) {
    }
}
