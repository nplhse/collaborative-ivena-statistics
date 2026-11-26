<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * The named steps of restoring a backup, shown as progress (ADR 038, "Restore").
 */
enum RestoreStep: string
{
    case Preflight = 'preflight';
    case Verification = 'verification';
    case Files = 'files';
    case Database = 'database';
    case Switch = 'switch';
    case Completion = 'completion';

    public function label(): string
    {
        return match ($this) {
            self::Preflight => 'Preflight checks',
            self::Verification => 'Backup verification',
            self::Files => 'File staging',
            self::Database => 'Database restore',
            self::Switch => 'File switch',
            self::Completion => 'Completion',
        };
    }
}
