<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * Completed or partial allocation imports one user uploaded for a hospital.
 */
final readonly class UploaderContribution
{
    public function __construct(
        public int $importCount,
        public \DateTimeImmutable $lastUploadAt,
    ) {
    }
}
