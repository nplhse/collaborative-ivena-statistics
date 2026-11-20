<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * Names and constants of the backup format (ADR 038, "Format").
 *
 * A backup is one directory `ivena-stats-<type>-<YYYYMMDDTHHMMSSZ>` holding manifest.json, database.dump and
 * files/<component>.tar.gz plus files/<component>.sha256. It is built under a hidden `.partial` name and only
 * renamed once everything is written, so a directory with the final name is always complete.
 */
final class BackupFormat
{
    public const string FORMAT = 'ivena-stats-backup';

    public const int VERSION = 1;

    public const string MANIFEST = 'manifest.json';

    public const string DATABASE_DUMP = 'database.dump';

    public const string TYPE_FULL = 'full';

    private const string PREFIX = 'ivena-stats-';

    private const string TIMESTAMP = 'Ymd\THis\Z';

    public static function directoryName(string $type, \DateTimeImmutable $createdAt): string
    {
        return self::PREFIX.$type.'-'.$createdAt->setTimezone(new \DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }

    public static function partialDirectoryName(string $directoryName): string
    {
        return '.'.$directoryName.'.partial';
    }

    public static function archivePath(BackupComponent $component): string
    {
        return 'files/'.$component->value.'.tar.gz';
    }

    public static function checksumListPath(BackupComponent $component): string
    {
        return 'files/'.$component->value.'.sha256';
    }
}
