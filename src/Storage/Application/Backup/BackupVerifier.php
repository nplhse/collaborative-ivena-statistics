<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

use App\Storage\Application\ByteFormatter;
use App\Storage\Application\Contract\ArchiveReaderInterface;
use App\Storage\Application\Contract\DumpReaderInterface;

/**
 * Verifies a backup without restoring it (ADR 038, "Verify").
 *
 * 1. Manifest: present, of a known format version, every field typed.
 * 2. Payloads: each listed file exists with the recorded size and SHA-256; other files are reported as warnings.
 * 3. Database dump: pg_restore reads its table of contents, and every table of the manifest has data in it.
 * 4. File archives: every entry is a safe, regular file; the files match the component's checksum list one by
 *    one, and their number and bytes match the manifest.
 *
 * Read-only: nothing in the backup or the application is changed. A damaged payload is reported and the steps
 * that need it are skipped; all other checks still run, so one run lists every problem.
 */
final readonly class BackupVerifier
{
    private const string PAYLOAD = '#^(database\.dump|files/[a-z]+\.(tar\.gz|sha256))$#';

    private const string CHECKSUM_LINE = '/^([0-9a-f]{64})  (.+)$/';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private DumpReaderInterface $dumpReader,
        private ArchiveReaderInterface $archiveReader,
    ) {
    }

    /**
     * @param callable(VerificationStep, ?string, bool): void $progress called with a null detail when a step
     *                                                                  starts, then once per check
     *
     * @throws BackupFailed when the backup cannot be verified at all (no readable manifest, no restore tool)
     */
    public function verify(string $backupDirectory, callable $progress): BackupVerification
    {
        $problems = [];
        $warnings = [];
        $step = VerificationStep::Manifest;
        $pass = static function (string $detail) use (&$step, $progress): void {
            $progress($step, $detail, true);
        };
        $fail = static function (string $detail, bool $record = true) use (&$step, &$problems, $progress): void {
            if ($record) {
                $problems[] = $detail;
            }
            $progress($step, $detail, false);
        };

        $toolVersion = $this->dumpReader->preflight();

        $progress($step, null, true);
        $manifest = ManifestReader::read($backupDirectory);
        $pass(sprintf('Known format, %s backup of %s (application %s)', $manifest->type, $manifest->createdAt->format('Y-m-d H:i:s \U\T\C'), $manifest->appVersion));
        if ($manifest->id !== basename($backupDirectory)) {
            $warnings[] = sprintf('The directory is named %s, the backup id is %s.', basename($backupDirectory), $manifest->id);
        }

        $step = VerificationStep::Payloads;
        $progress($step, null, true);
        $intact = $this->payloads($backupDirectory, $manifest, $pass, $fail);
        foreach ($this->unlistedFiles($backupDirectory, $manifest) as $relative) {
            $warnings[] = sprintf('%s is not part of the backup and is ignored.', $relative);
        }

        $step = VerificationStep::Database;
        $progress($step, null, true);
        if (isset($intact[BackupFormat::DATABASE_DUMP])) {
            $this->database($backupDirectory, $manifest, $toolVersion, $pass, $fail);
        } else {
            $fail(sprintf('Skipped: %s is missing or damaged.', BackupFormat::DATABASE_DUMP), false);
        }

        $step = VerificationStep::Files;
        $progress($step, null, true);
        foreach ($manifest->files as $name => $archived) {
            $this->files($backupDirectory, BackupComponent::from($name), $archived, $intact, $pass, $fail);
        }

        return new BackupVerification($manifest, $problems, $warnings);
    }

    /**
     * @param callable(string): void       $pass
     * @param callable(string, bool): void $fail
     *
     * @return array<string, true> the payloads that match the manifest
     */
    private function payloads(string $directory, BackupManifest $manifest, callable $pass, callable $fail): array
    {
        $intact = [];
        foreach ($manifest->payloads as $relative => $payload) {
            if (1 !== preg_match(self::PAYLOAD, $relative)) {
                $fail(sprintf('The manifest lists the unexpected payload %s.', $relative), true);

                continue;
            }

            $path = $directory.'/'.$relative;
            if (!is_file($path) || is_link($path)) {
                $fail(sprintf('%s is missing.', $relative), true);

                continue;
            }

            $bytes = filesize($path);
            if (false === $bytes || $bytes !== $payload['bytes']) {
                $fail(sprintf('%s has %d bytes; the manifest records %d.', $relative, false === $bytes ? 0 : $bytes, $payload['bytes']), true);

                continue;
            }

            if (hash_file('sha256', $path) !== $payload['sha256']) {
                $fail(sprintf('%s does not match its SHA-256 checksum.', $relative), true);

                continue;
            }

            $intact[$relative] = true;
            $pass(sprintf('%s (%s)', $relative, ByteFormatter::format($bytes)));
        }

        foreach ($manifest->files as $name => $archived) {
            if (!$archived->hasArchive()) {
                continue;
            }

            $component = BackupComponent::from($name);
            foreach ([BackupFormat::archivePath($component), BackupFormat::checksumListPath($component)] as $relative) {
                if (!\array_key_exists($relative, $manifest->payloads)) {
                    $fail(sprintf('The manifest records %d %s files but lists no %s.', $archived->files, $name, $relative), true);
                }
            }
        }

        return $intact;
    }

    /**
     * @param callable(string): void       $pass
     * @param callable(string, bool): void $fail
     */
    private function database(string $directory, BackupManifest $manifest, string $toolVersion, callable $pass, callable $fail): void
    {
        try {
            $tables = $this->dumpReader->tables($directory.'/'.BackupFormat::DATABASE_DUMP);
        } catch (BackupFailed $failure) {
            $fail($failure->getMessage(), true);

            return;
        }

        $missing = array_values(array_diff(array_keys($manifest->database->tableRows), $tables));
        if ([] !== $missing) {
            $fail(sprintf('The dump has no data for %d tables of the manifest: %s.', \count($missing), $this->sample($missing)), true);

            return;
        }

        $pass(sprintf('%d tables readable with %s; latest migration %s', \count($manifest->database->tableRows), $toolVersion, $manifest->database->latestMigration() ?? '(none)'));
    }

    /**
     * @param array<string, true>          $intact
     * @param callable(string): void       $pass
     * @param callable(string, bool): void $fail
     */
    private function files(string $directory, BackupComponent $component, ArchivedFiles $archived, array $intact, callable $pass, callable $fail): void
    {
        $name = $component->value;
        if (!$archived->hasArchive()) {
            $pass(sprintf('%s: no files%s', $name, $archived->sourceMissing ? ' (the source directory was missing)' : ''));

            return;
        }

        $archive = BackupFormat::archivePath($component);
        $list = BackupFormat::checksumListPath($component);
        if (!isset($intact[$archive], $intact[$list])) {
            $fail(sprintf('%s: skipped, its payloads are missing or damaged.', $name), false);

            return;
        }

        try {
            $expected = $this->checksumList($directory.'/'.$list, $list);
            $entries = $this->archiveReader->entries($directory.'/'.$archive);
        } catch (BackupFailed $failure) {
            $fail(sprintf('%s: %s', $name, $failure->getMessage()), true);

            return;
        }

        $missing = array_keys(array_diff_key($expected, $entries));
        $unexpected = array_keys(array_diff_key($entries, $expected));
        $changed = array_keys(array_filter(array_intersect_key($entries, $expected), static fn (array $entry, string $path): bool => $entry['sha256'] !== $expected[$path], \ARRAY_FILTER_USE_BOTH));
        if ([] !== $missing || [] !== $unexpected || [] !== $changed) {
            $fail(sprintf(
                '%s: %d files missing from the archive, %d not in the checksum list, %d with a wrong checksum: %s.',
                $name,
                \count($missing),
                \count($unexpected),
                \count($changed),
                $this->sample([...$missing, ...$unexpected, ...$changed]),
            ), true);

            return;
        }

        $bytes = array_sum(array_column($entries, 'size'));
        if (\count($entries) !== $archived->files || $bytes !== $archived->bytes) {
            $fail(sprintf('%s: the archive holds %d files with %d bytes; the manifest records %d files with %d bytes.', $name, \count($entries), $bytes, $archived->files, $archived->bytes), true);

            return;
        }

        $pass(sprintf('%s: %d files (%s) match their checksums', $name, $archived->files, ByteFormatter::format($bytes)));
    }

    /**
     * @return array<string, string> SHA-256 by relative path
     *
     * @throws BackupFailed
     */
    private function checksumList(string $file, string $relative): array
    {
        $lines = @file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            throw new BackupFailed(sprintf('%s cannot be read.', $relative));
        }

        $checksums = [];
        foreach ($lines as $number => $line) {
            if (1 !== preg_match(self::CHECKSUM_LINE, $line, $match) || \array_key_exists($match[2], $checksums)) {
                throw new BackupFailed(sprintf('Line %d of %s is invalid.', $number + 1, $relative));
            }
            $checksums[$match[2]] = $match[1];
        }

        return $checksums;
    }

    /**
     * @return list<string> relative paths of regular files in the backup that neither manifest nor payloads name
     */
    private function unlistedFiles(string $directory, BackupManifest $manifest): array
    {
        $unlisted = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            $relative = substr($entry->getPathname(), \strlen($directory) + 1);
            if (BackupFormat::MANIFEST !== $relative && !\array_key_exists($relative, $manifest->payloads)) {
                $unlisted[] = $relative;
            }
        }
        sort($unlisted);

        return $unlisted;
    }

    /**
     * @param list<string> $items
     */
    private function sample(array $items): string
    {
        $shown = \array_slice($items, 0, 5);

        return implode(', ', $shown).(\count($items) > \count($shown) ? sprintf(' and %d more', \count($items) - \count($shown)) : '');
    }
}
