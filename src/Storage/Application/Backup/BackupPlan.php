<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * What a backup run is going to do, shown to the operator before it starts (ADR 038, "Create").
 */
final readonly class BackupPlan
{
    /**
     * @param list<FileComponentSource> $fileSources
     * @param list<string>              $warnings
     */
    public function __construct(
        public string $type,
        public \DateTimeImmutable $createdAt,
        public string $targetDirectory,
        public string $backupDirectory,
        public string $partialDirectory,
        public string $database,
        public array $fileSources,
        public array $warnings,
    ) {
    }
}
