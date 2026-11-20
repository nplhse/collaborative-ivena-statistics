<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * The named steps of verifying a backup, shown as progress (ADR 038, "Verify").
 */
enum VerificationStep: string
{
    case Manifest = 'manifest';
    case Payloads = 'payloads';
    case Database = 'database';
    case Files = 'files';

    public function label(): string
    {
        return match ($this) {
            self::Manifest => 'Manifest',
            self::Payloads => 'Payload checksums',
            self::Database => 'Database dump',
            self::Files => 'File archives',
        };
    }
}
