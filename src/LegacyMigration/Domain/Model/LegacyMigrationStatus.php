<?php

declare(strict_types=1);

namespace App\LegacyMigration\Domain\Model;

final readonly class LegacyMigrationStatus
{
    /**
     * @param array<string, int> $importStatusCounts
     */
    public function __construct(
        public bool $installed,
        public int $userMappings,
        public int $hospitalMappings,
        public int $importMappings,
        public int $allocationMappings,
        public int $migratedCountSum,
        public array $importStatusCounts,
        public ?string $lastErrorMessage,
    ) {
    }
}

