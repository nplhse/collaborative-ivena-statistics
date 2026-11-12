<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * Allocation imports of one hospital. Relevant = completed or partial; failed, cancelled and pending imports are
 * counted apart and never add data.
 */
final readonly class HospitalImportSummary
{
    public function __construct(
        public int $relevant,
        public int $partial,
        public int $failed,
        public int $cancelled,
        public int $pending,
        public ?\DateTimeImmutable $lastUploadAt,
        public int $ownImports,
        public ?\DateTimeImmutable $ownLastUploadAt,
    ) {
    }
}
