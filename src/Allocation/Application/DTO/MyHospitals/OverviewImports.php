<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * Import figures summed over the hospitals with IMPORT permission (`hospitals` of them).
 */
final readonly class OverviewImports
{
    public function __construct(
        public int $hospitals,
        public int $relevant,
        public int $problems,
        public ?\DateTimeImmutable $lastUploadAt,
        public int $ownImports,
        public ?\DateTimeImmutable $ownLastUploadAt,
    ) {
    }
}
