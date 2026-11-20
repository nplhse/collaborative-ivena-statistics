<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * A part of the application state that a backup can hold (ADR 038, "Components").
 *
 * The names are logical: they stay the same when a directory moves (for example media out of public/, #489),
 * only the configured source path changes.
 */
enum BackupComponent: string
{
    case Database = 'database';
    case Imports = 'imports';
    case Media = 'media';
    case Geo = 'geo';
    case Reference = 'reference';

    public function isFiles(): bool
    {
        return self::Database !== $this;
    }

    /**
     * @return list<self>
     */
    public static function fileComponents(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $component): bool => $component->isFiles()));
    }
}
