<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\DTO;

/** @psalm-immutable */
final readonly class HospitalIsochroneFetchReport
{
    /**
     * @param list<array{0: string, 1: string, 2: string}> $rows
     */
    public function __construct(
        public bool $success,
        public bool $dryRun,
        public ?string $error = null,
        public array $rows = [],
        public int $inspected = 0,
        public int $missingCoords = 0,
        public int $skipped = 0,
        public int $toFetch = 0,
        public int $written = 0,
        public int $failed = 0,
    ) {
    }
}
