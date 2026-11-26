<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Finds complete backups (ADR 038, "Format"): directories with the final backup name and a manifest.
 *
 * Unfinished `.partial` directories and legacy dumps are never listed.
 */
final readonly class BackupLocator
{
    private const string NAME = '/^ivena-stats-[a-z]+-(\d{8}T\d{6}Z)$/';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        #[Autowire(param: 'app.backup.directory')]
        private string $backupDirectory,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function directory(): string
    {
        return Path::makeAbsolute($this->backupDirectory, $this->projectDir);
    }

    /**
     * Complete backups in the backup directory, newest first.
     *
     * @return list<string> absolute paths
     */
    public function all(): array
    {
        $directory = $this->directory();
        $entries = is_dir($directory) ? scandir($directory) : false;
        if (false === $entries) {
            return [];
        }

        $backups = [];
        foreach ($entries as $entry) {
            $path = $directory.'/'.$entry;
            if (1 === preg_match(self::NAME, $entry, $match) && is_dir($path) && is_file($path.'/'.BackupFormat::MANIFEST)) {
                $backups[$match[1].$entry] = $path;
            }
        }

        krsort($backups, \SORT_STRING);

        return array_values($backups);
    }

    /**
     * Resolves a backup given as path (absolute or relative to the project) or as name in the backup directory;
     * without one, the newest complete backup.
     *
     * @throws BackupFailed when no backup matches
     */
    public function resolve(?string $backup): string
    {
        if (null === $backup || '' === $backup) {
            $all = $this->all();
            if ([] === $all) {
                throw new BackupFailed(sprintf('There is no complete backup in %s.', $this->directory()), 'Create one with app:backup:create, or name the backup directory as argument.');
            }

            return $all[0];
        }

        foreach ([Path::makeAbsolute($backup, $this->projectDir), Path::join($this->directory(), $backup)] as $candidate) {
            if (is_dir($candidate)) {
                return Path::canonicalize($candidate);
            }
        }

        throw new BackupFailed(sprintf('The backup %s does not exist.', $backup), sprintf('Give a backup directory, or the name of a backup in %s.', $this->directory()));
    }
}
