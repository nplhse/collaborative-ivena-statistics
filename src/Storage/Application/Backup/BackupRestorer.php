<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

use App\Storage\Application\Contract\ArchiveExtractorInterface;
use App\Storage\Application\Contract\DatabaseRestorerInterface;
use App\Storage\Application\Contract\MigrationCatalogInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Restores a full backup: database and file components (ADR 038, "Restore").
 *
 * Nothing is replaced before the backup has been verified and its files have been staged:
 *
 * 1. Preflight: lock, restore tool, empty message queues, writable target directories.
 * 2. Verification: the complete verify of BackupVerifier; any problem stops the restore.
 * 3. File staging: each archive is extracted next to its target directory and checked against its list.
 * 4. Database: schema public is dropped and recreated, the dump is restored in one transaction, and the row counts
 *    are compared with the manifest. With "keep previous" the schema is moved aside instead and moved back on any
 *    failure; this needs space for a second copy of the database.
 * 5. File switch: each target directory is renamed to `<dir>.pre-restore-<time>` and the staged directory takes
 *    its place. A failure switches the directories back (and, with "keep previous", the database too).
 * 6. Completion: the previous directories (and schema) are removed; with "keep previous" the directories are kept
 *    for the operator to remove after checking the application.
 *
 * Every failure states what it left behind.
 */
final readonly class BackupRestorer
{
    private const string SUFFIX = 'Ymd\THis\Z';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private BackupVerifier $verifier,
        private DatabaseRestorerInterface $database,
        private ArchiveExtractorInterface $extractor,
        private MigrationCatalogInterface $migrations,
        private BackupSources $sources,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param bool $keepPrevious keep database and directories until the restore is complete (rollback possible;
     *                           needs space for a second copy)
     *
     * @throws BackupFailed when the backup cannot be restored by this application
     */
    public function plan(string $backupDirectory, bool $keepPrevious = false): RestorePlan
    {
        try {
            $manifest = ManifestReader::read($backupDirectory);
        } catch (BackupFailed $failure) {
            throw $failure->inStep(RestoreStep::Preflight);
        }

        $migrations = MigrationState::compare($manifest->database->executedMigrations, $this->migrations->available());
        if ($migrations->isNewer()) {
            throw new BackupFailed(sprintf('The backup holds %d migration(s) this application does not know, e.g. %s.', \count($migrations->unknown), implode(', ', \array_slice($migrations->unknown, -3))), sprintf('The backup was made with %s. Restore it with that version or a newer one.', $manifest->appVersion), RestoreStep::Preflight);
        }

        $warnings = [];
        if ($migrations->isOlder()) {
            $warnings[] = sprintf('The backup is %d migration(s) behind this application. Run "php bin/console doctrine:migrations:migrate" after the restore.', \count($migrations->pending));
        }

        $targets = [];
        foreach ($this->sources->fileComponents() as $source) {
            $archived = $manifest->files[$source->component->value] ?? new ArchivedFiles(0, 0, 0, sourceMissing: true);
            if ($archived->sourceMissing) {
                // Most likely another path configuration; replacing the directory with nothing could lose files.
                $warnings[] = sprintf('The backup has no %s directory (it was missing when the backup was made); %s is left unchanged.', $source->component->value, $source->path);

                continue;
            }

            $real = is_dir($source->path) ? realpath($source->path) : false;
            $targets[] = new RestoreTarget($source->component, false === $real ? $source->path : $real, $archived, false !== $real);
        }

        return new RestorePlan($backupDirectory, $manifest, $this->database->target(), $targets, $migrations, $warnings, $keepPrevious);
    }

    /**
     * @param callable(RestoreStep, ?string, bool): void $progress called with a null detail when a step starts,
     *                                                             then once per finished part of it
     *
     * @throws BackupFailed with the step that failed and the state it left behind
     */
    public function restore(RestorePlan $plan, callable $progress): RestoreResult
    {
        $lock = $this->lockFactory->createLock(BackupCreator::LOCK, null);
        if (!$lock->acquire()) {
            throw new BackupFailed('Another backup or restore is running.', 'Wait until it has finished and try again.', RestoreStep::Preflight, state: 'Nothing was changed.');
        }

        $filesystem = new Filesystem();
        $suffix = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(self::SUFFIX);
        $step = RestoreStep::Preflight;
        /** @var array<string, string> $staged staging directory by target path */
        $staged = [];

        try {
            $progress($step, null, true);
            $progress($step, $this->database->preflight(), true);
            $this->checkTargets($plan, $suffix);
            $progress($step, sprintf('%d target directories writable', \count($plan->targets)), true);

            $step = RestoreStep::Verification;
            $progress($step, null, true);
            $verification = $this->verifier->verify($plan->backupDirectory, static function (VerificationStep $verificationStep, ?string $detail, bool $ok) use ($progress, $step): void {
                if (null !== $detail) {
                    $progress($step, $verificationStep->label().': '.$detail, $ok);
                }
            });
            if (!$verification->isValid()) {
                throw new BackupFailed(sprintf('The backup has %d problem(s): %s', \count($verification->problems), implode(' ', $verification->problems)), 'Run app:backup:verify for details, or restore another backup.');
            }

            $step = RestoreStep::Files;
            $progress($step, null, true);
            foreach ($plan->targets as $target) {
                $staging = $this->stagingDirectory($target->path, $suffix);
                $permissions = $target->exists ? fileperms($target->path) : false;
                $filesystem->mkdir($staging, false === $permissions ? 0o755 : $permissions & 0o777);
                $staged[$target->path] = $staging;
                $this->stage($plan->backupDirectory, $target, $staging);
                $progress($step, sprintf('%s: %d files ready', $target->component->value, $target->archived->files), true);
            }

            $step = RestoreStep::Database;
            $progress($step, null, true);
            $stash = $plan->keepPrevious ? $this->restoreKeepingPrevious($plan, $suffix) : $this->restoreReplacing($plan, $progress);
            $progress($step, sprintf('%d tables, %s rows, as recorded in the manifest', \count($plan->manifest->database->tableRows), number_format(array_sum($plan->manifest->database->tableRows))), true);

            $step = RestoreStep::Switch;
            $progress($step, null, true);
            $previous = $this->switch($plan, $staged, $suffix, $stash, $progress);
            $staged = [];

            $step = RestoreStep::Completion;
            $progress($step, null, true);

            return $this->complete($plan, $previous, $stash, $progress);
        } catch (\Throwable $exception) {
            $leftovers = [];
            foreach ($staged as $staging) {
                try {
                    $filesystem->remove($staging);
                } catch (\Throwable) {
                    $leftovers[] = $staging;
                }
            }

            $failure = $exception instanceof BackupFailed
                ? $exception->inStep($step)
                : new BackupFailed(sprintf('Unexpected error: %s', $exception->getMessage()), 'Run the command again with -vvv for details.', $step, $exception);
            $state = $failure->state ?? 'The database and the files were not changed.';

            throw $failure->withState($state.([] === $leftovers ? '' : sprintf(' Remove the unfinished restore directories by hand: %s.', implode(', ', $leftovers))));
        } finally {
            $lock->release();
        }
    }

    /**
     * Drops the current schema, then restores; needs no space for a second copy, but has no way back.
     *
     * @param callable(RestoreStep, ?string, bool): void $progress
     *
     * @throws BackupFailed
     */
    private function restoreReplacing(RestorePlan $plan, callable $progress): null
    {
        $this->database->clear();
        $progress(RestoreStep::Database, 'Current database schema dropped', true);

        try {
            $this->database->restore($plan->backupDirectory.'/'.BackupFormat::DATABASE_DUMP);
            $this->checkRows($plan->manifest->database->tableRows, $this->database->tableRows());
        } catch (\Throwable $exception) {
            $failure = $exception instanceof BackupFailed ? $exception : new BackupFailed(sprintf('Unexpected error: %s', $exception->getMessage()), previous: $exception);

            throw $failure->inStep(RestoreStep::Database)->withState('The files were not changed. The current database was dropped and the backup is not (completely) restored: fix the cause and restore this backup or another one again.');
        }

        return null;
    }

    /**
     * Moves the current schema aside, then restores; moves it back on failure.
     *
     * @return string the stashed schema
     *
     * @throws BackupFailed
     */
    private function restoreKeepingPrevious(RestorePlan $plan, string $suffix): string
    {
        $stash = $this->database->stash(strtolower($suffix));
        try {
            $this->database->restore($plan->backupDirectory.'/'.BackupFormat::DATABASE_DUMP);
            $this->checkRows($plan->manifest->database->tableRows, $this->database->tableRows());
        } catch (\Throwable $exception) {
            try {
                $this->database->rollback($stash);
            } catch (BackupFailed $rollback) {
                $failure = $exception instanceof BackupFailed ? $exception : new BackupFailed($exception->getMessage(), previous: $exception);

                throw $failure->inStep(RestoreStep::Database)->withState(sprintf('The files were not changed. The database could not be switched back (%s): the previous schema is kept as %s; rename it to "public" by hand.', $rollback->getMessage(), $stash));
            }

            throw $exception;
        }

        return $stash;
    }

    /**
     * Removes what the restore replaced: always the stashed schema, the previous directories unless they are kept.
     *
     * @param list<string>                               $previous
     * @param callable(RestoreStep, ?string, bool): void $progress
     */
    private function complete(RestorePlan $plan, array $previous, ?string $stash, callable $progress): RestoreResult
    {
        $warnings = [];
        if (null !== $stash) {
            try {
                $this->database->discard($stash);
                $progress(RestoreStep::Completion, 'Previous database schema removed', true);
            } catch (BackupFailed $failure) {
                $warnings[] = sprintf('The previous database schema %s could not be removed: %s Drop it by hand.', $stash, $failure->getMessage());
            }
        }

        if ($plan->keepPrevious) {
            return new RestoreResult($previous, \count($plan->migrations->pending), $warnings);
        }

        $kept = [];
        $filesystem = new Filesystem();
        foreach ($previous as $directory) {
            try {
                $filesystem->remove($directory);
                $progress(RestoreStep::Completion, sprintf('Removed %s', $directory), true);
            } catch (\Throwable $exception) {
                $kept[] = $directory;
                $warnings[] = sprintf('%s could not be removed: %s Remove it by hand.', $directory, $exception->getMessage());
            }
        }

        return new RestoreResult($kept, \count($plan->migrations->pending), $warnings);
    }

    private function stagingDirectory(string $target, string $suffix): string
    {
        return \dirname($target).'/.'.basename($target).'.restore-'.$suffix;
    }

    private function previousDirectory(string $target, string $suffix): string
    {
        return $target.'.pre-restore-'.$suffix;
    }

    /**
     * @throws BackupFailed
     */
    private function checkTargets(RestorePlan $plan, string $suffix): void
    {
        foreach ($plan->targets as $target) {
            $parent = \dirname($target->path);
            if (!is_dir($parent) || !is_writable($parent)) {
                throw new BackupFailed(sprintf('The directory %s is not writable.', $parent), sprintf('The restore creates the %s directory next to the current one there.', $target->component->value));
            }
            if (file_exists($this->stagingDirectory($target->path, $suffix)) || file_exists($this->previousDirectory($target->path, $suffix))) {
                throw new BackupFailed(sprintf('A restore directory for %s already exists.', $target->component->value), 'Wait a second and run the command again.');
            }
        }
    }

    /**
     * Extracts the component archive into $staging and checks every file against the checksum list.
     *
     * @throws BackupFailed
     */
    private function stage(string $backupDirectory, RestoreTarget $target, string $staging): void
    {
        if (!$target->archived->hasArchive()) {
            return;
        }

        $this->extractor->extract($backupDirectory.'/'.BackupFormat::archivePath($target->component), $staging);

        $list = @file($backupDirectory.'/'.BackupFormat::checksumListPath($target->component), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $list) {
            throw new BackupFailed(sprintf('The checksum list of %s cannot be read.', $target->component->value));
        }

        foreach ($list as $line) {
            [$hash, $relative] = explode('  ', $line, 2) + [1 => ''];
            $file = $staging.'/'.$relative;
            if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== $hash) {
                throw new BackupFailed(sprintf('%s/%s does not match its checksum after extraction.', $target->component->value, $relative), 'Check the free disk space and run the restore again.');
            }
        }

        $extracted = iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS)));
        if ($extracted !== \count($list)) {
            throw new BackupFailed(sprintf('%s: %d files were extracted, the backup holds %d.', $target->component->value, $extracted, \count($list)));
        }
    }

    /**
     * @param array<string, int> $expected
     * @param array<string, int> $actual
     *
     * @throws BackupFailed
     */
    private function checkRows(array $expected, array $actual): void
    {
        $different = [];
        foreach ($expected as $table => $rows) {
            // Manifests made before #1452 count the runtime tables; their rows are not restored.
            if (\in_array($table, BackupFormat::RUNTIME_TABLES, true)) {
                continue;
            }

            if (($actual[$table] ?? null) !== $rows) {
                $different[] = sprintf('%s (%s instead of %d)', $table, isset($actual[$table]) ? (string) $actual[$table] : 'missing', $rows);
            }
        }

        if ([] !== $different) {
            throw new BackupFailed(sprintf('The restored database differs from the manifest: %s.', implode(', ', \array_slice($different, 0, 5))), 'Run app:backup:verify on the backup and check the PostgreSQL log.');
        }
    }

    /**
     * Moves every target aside and the staged directory into its place; undoes all of it on failure.
     *
     * @param array<string, string>                      $staged
     * @param callable(RestoreStep, ?string, bool): void $progress
     *
     * @return list<string> the previous directories
     *
     * @throws BackupFailed
     */
    private function switch(RestorePlan $plan, array $staged, string $suffix, ?string $stash, callable $progress): array
    {
        /** @var list<array{target: string, previous: ?string}> $switched */
        $switched = [];

        try {
            foreach ($plan->targets as $target) {
                $previous = null;
                if (file_exists($target->path)) {
                    $previous = $this->previousDirectory($target->path, $suffix);
                    $this->rename($target->path, $previous);
                }
                $switched[] = ['target' => $target->path, 'previous' => $previous];
                $this->rename($staged[$target->path], $target->path);
                $progress(RestoreStep::Switch, sprintf('%s: %s', $target->component->value, $target->path), true);
            }
        } catch (BackupFailed $failure) {
            throw $failure->withState($this->undoSwitch($switched, $staged, $stash));
        }

        return array_values(array_filter(array_column($switched, 'previous')));
    }

    /**
     * @param list<array{target: string, previous: ?string}> $switched
     * @param array<string, string>                          $staged
     */
    private function undoSwitch(array $switched, array $staged, ?string $stash): string
    {
        $leftovers = [];
        foreach (array_reverse($switched) as $entry) {
            try {
                if (is_dir($entry['target']) && !file_exists($staged[$entry['target']])) {
                    $this->rename($entry['target'], $staged[$entry['target']]);
                }
                if (null !== $entry['previous']) {
                    $this->rename($entry['previous'], $entry['target']);
                }
            } catch (BackupFailed) {
                $leftovers[] = sprintf('%s (previous content in %s)', $entry['target'], $entry['previous'] ?? 'nothing');
            }
        }

        $files = [] === $leftovers ? 'The files were not changed' : sprintf('These directories could not be switched back: %s', implode(', ', $leftovers));

        if (null === $stash) {
            return $files.'; the database already holds the backup. Fix the cause and restore again, so files and database match.';
        }

        try {
            $this->database->rollback($stash);
        } catch (BackupFailed $failure) {
            return sprintf('%s; the database holds the backup, the previous schema is kept as %s (%s).', $files, $stash, $failure->getMessage());
        }

        return [] === $leftovers ? 'The database and the files were not changed.' : $files.'; the database was not changed.';
    }

    /**
     * @throws BackupFailed
     */
    private function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new BackupFailed(sprintf('%s could not be renamed to %s.', $from, $to), 'Check the permissions of the directory.');
        }
    }
}
