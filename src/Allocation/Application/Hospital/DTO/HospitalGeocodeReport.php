<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\DTO;

/** @psalm-immutable */
final readonly class HospitalGeocodeReport
{
    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows
     */
    public function __construct(
        public bool $success,
        public bool $dryRun,
        public ?string $error = null,
        public string $scopeLabel = '',
        public array $rows = [],
        public int $inspected = 0,
        public int $skipped = 0,
        public int $missingAddress = 0,
        public int $toGeocode = 0,
        public int $written = 0,
        public int $unusableMatch = 0,
        public int $failed = 0,
        public bool $rateLimited = false,
    ) {
    }
}
