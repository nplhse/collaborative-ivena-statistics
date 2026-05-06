<?php

declare(strict_types=1);

namespace App\LegacyMigration\Domain\Model;

/** @psalm-suppress UnusedClass */
final readonly class LegacyImportMapping
{
    public function __construct(
        public int $legacyImportId,
        public ?int $newImportId,
        public LegacyImportMigrationStatus $status,
        public ?int $lastAllocationId,
        public int $migratedCount,
        public ?string $errorMessage,
    ) {
    }
}
