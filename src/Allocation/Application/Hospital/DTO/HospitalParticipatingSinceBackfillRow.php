<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\DTO;

final readonly class HospitalParticipatingSinceBackfillRow
{
    /**
     * @param 'audit'|'import'|null $source
     */
    public function __construct(
        public int $hospitalId,
        public string $hospitalName,
        public ?string $source,
        public ?\DateTimeImmutable $participatingSince,
    ) {
    }
}
