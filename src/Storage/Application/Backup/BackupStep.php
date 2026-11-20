<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * The named steps of creating a backup, shown as progress (ADR 038, "Progress").
 */
enum BackupStep: string
{
    case Preflight = 'preflight';
    case Database = 'database';
    case Files = 'files';
    case Checksums = 'checksums';
    case Completion = 'completion';

    public function label(): string
    {
        return match ($this) {
            self::Preflight => 'Preflight checks',
            self::Database => 'Database dump',
            self::Files => 'File collection',
            self::Checksums => 'Integrity checksums',
            self::Completion => 'Completion',
        };
    }
}
