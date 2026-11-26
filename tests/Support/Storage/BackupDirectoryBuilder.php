<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\Storage\Application\Backup\ArchivedFiles;
use App\Storage\Application\Backup\BackupComponent;
use App\Storage\Application\Backup\BackupFormat;
use App\Storage\Application\Backup\BackupManifest;
use App\Storage\Application\Backup\DatabaseDump;

/**
 * Writes a complete backup directory for verify tests: manifest, a stand-in dump and one media archive.
 *
 * The dump and the archive are placeholders; tests read them with fake readers. Their checksums in the
 * manifest are real, so tampering with a payload is detected.
 */
final class BackupDirectoryBuilder
{
    public const string NAME = 'ivena-stats-full-20261120T103005Z';

    /**
     * @param array<string, string> $mediaFiles content by relative path; empty for no media archive
     * @param array<string, int>    $tableRows
     */
    public static function write(string $parent, array $mediaFiles = ['a.txt' => 'alpha', 'b/c.txt' => 'gamma'], array $tableRows = ['allocation' => 4, 'hospital' => 2], string $name = self::NAME): string
    {
        $directory = $parent.'/'.$name;
        mkdir($directory.'/files', 0o700, true);
        file_put_contents($directory.'/'.BackupFormat::DATABASE_DUMP, 'dump');

        $files = [];
        foreach (BackupComponent::fileComponents() as $component) {
            $files[$component->value] = new ArchivedFiles(0, 0, 0);
        }

        if ([] !== $mediaFiles) {
            ksort($mediaFiles, \SORT_STRING);
            $list = '';
            foreach ($mediaFiles as $path => $content) {
                $list .= hash('sha256', $content).'  '.$path."\n";
            }
            file_put_contents($directory.'/'.BackupFormat::archivePath(BackupComponent::Media), 'archive');
            file_put_contents($directory.'/'.BackupFormat::checksumListPath(BackupComponent::Media), $list);
            $files[BackupComponent::Media->value] = new ArchivedFiles(\count($mediaFiles), array_sum(array_map(strlen(...), $mediaFiles)), 0);
        }

        $payloads = [];
        foreach ([BackupFormat::DATABASE_DUMP, BackupFormat::archivePath(BackupComponent::Media), BackupFormat::checksumListPath(BackupComponent::Media)] as $relative) {
            $path = $directory.'/'.$relative;
            if (is_file($path)) {
                $payloads[$relative] = ['bytes' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
            }
        }
        ksort($payloads, \SORT_STRING);

        $manifest = new BackupManifest(
            $name,
            BackupFormat::TYPE_FULL,
            new \DateTimeImmutable('2026-11-20 10:30:05 UTC'),
            'v0.3.0-beta',
            'prod',
            new DatabaseDump('18.4', 'pg_dump (PostgreSQL) 18.4', ['DoctrineMigrations\Version20260101000000', 'DoctrineMigrations\Version20261119220659'], $tableRows),
            $files,
            $payloads,
        );
        file_put_contents($directory.'/'.BackupFormat::MANIFEST, $manifest->toJson());

        return $directory;
    }

    /**
     * The entries a correct archive of $mediaFiles holds, as ArchiveReaderInterface returns them.
     *
     * @param array<string, string> $mediaFiles
     *
     * @return array<string, array{size: int, sha256: string}>
     */
    public static function entries(array $mediaFiles = ['a.txt' => 'alpha', 'b/c.txt' => 'gamma']): array
    {
        $entries = [];
        foreach ($mediaFiles as $path => $content) {
            $entries[$path] = ['size' => \strlen($content), 'sha256' => hash('sha256', $content)];
        }
        ksort($entries, \SORT_STRING);

        return $entries;
    }

    /**
     * Rewrites the manifest through $edit, e.g. to fake a newer format version.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    public static function editManifest(string $directory, callable $edit): void
    {
        $file = $directory.'/'.BackupFormat::MANIFEST;
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($file), true, 32, \JSON_THROW_ON_ERROR);
        file_put_contents($file, json_encode($edit($data), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }
}
