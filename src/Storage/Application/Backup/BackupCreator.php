<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

use App\Storage\Application\Contract\DatabaseDumperInterface;
use App\Storage\Application\Contract\FileArchiverInterface;
use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Domain\Enum\MeasurementMode;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\LockFactory;

/**
 * Creates a full backup: database dump, file components, checksums and manifest (ADR 038, "Create").
 *
 * Everything is written below a hidden `.partial` directory. Only after the last write is the directory renamed
 * to its final name, so an interrupted or failed run never looks like a usable backup. On failure the partial
 * directory of this run is removed; nothing else in the target directory is touched.
 */
final readonly class BackupCreator
{
    public const string LOCK = 'backup-maintenance';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private DatabaseDumperInterface $dumper,
        private FileArchiverInterface $archiver,
        private BackupSources $sources,
        private StorageLayoutDetectorInterface $layoutDetector,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(param: 'app.version')]
        private string $appVersion,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * @throws BackupFailed when the target directory cannot hold a backup
     */
    public function plan(string $targetDirectory): BackupPlan
    {
        $target = Path::makeAbsolute($targetDirectory, $this->projectDir);
        $publicDirectory = Path::join($this->projectDir, 'public');
        if (Path::isBasePath($publicDirectory, $target)) {
            throw new BackupFailed(sprintf('The backup directory %s lies below public/ and could be served by the web server.', $target), 'Choose a directory outside the public web root, for example var/backups or ~/backups.', BackupStep::Preflight);
        }

        $createdAt = $this->clock->now();
        $name = BackupFormat::directoryName(BackupFormat::TYPE_FULL, $createdAt);

        $warnings = [];
        $layout = $this->layoutDetector->detect();
        if (MeasurementMode::Deployer === $layout->mode && $layout->currentRelease instanceof \App\Storage\Application\Layout\ReleaseDirectory
            && Path::isBasePath(Path::join($layout->root, 'releases'), $target)) {
            $warnings[] = 'The backup directory lies inside a Deployer release and is deleted with that release. Prefer a directory outside the deploy path, for example ~/backups.';
        }

        return new BackupPlan(
            type: BackupFormat::TYPE_FULL,
            createdAt: $createdAt,
            targetDirectory: $target,
            backupDirectory: Path::join($target, $name),
            partialDirectory: Path::join($target, BackupFormat::partialDirectoryName($name)),
            database: $this->dumper->target(),
            fileSources: $this->sources->fileComponents(),
            warnings: $warnings,
        );
    }

    /**
     * @param callable(BackupStep, string): void $progress called when a step starts (empty detail) and for each
     *                                                     finished part of it
     *
     * @throws BackupFailed with the step that failed
     */
    public function create(BackupPlan $plan, callable $progress): BackupManifest
    {
        $lock = $this->lockFactory->createLock(self::LOCK, null);
        if (!$lock->acquire()) {
            throw new BackupFailed('Another backup or restore is running.', 'Wait until it has finished and try again.', BackupStep::Preflight);
        }

        $filesystem = new Filesystem();
        $createdPartial = false;
        $step = BackupStep::Preflight;

        try {
            $progress($step, '');
            $this->preflight($plan, $progress);

            $filesystem->mkdir($plan->partialDirectory.'/files', 0o700);
            $filesystem->chmod($plan->partialDirectory, 0o700);
            $createdPartial = true;

            $step = BackupStep::Database;
            $progress($step, '');
            $database = $this->dumper->dump(Path::join($plan->partialDirectory, BackupFormat::DATABASE_DUMP));
            $progress($step, sprintf('%d tables, %s rows', \count($database->tableRows), number_format(array_sum($database->tableRows))));

            $step = BackupStep::Files;
            $progress($step, '');
            $files = [];
            foreach ($plan->fileSources as $source) {
                $archived = $this->archiver->archive(
                    $source,
                    Path::join($plan->partialDirectory, BackupFormat::archivePath($source->component)),
                    Path::join($plan->partialDirectory, BackupFormat::checksumListPath($source->component)),
                );
                $files[$source->component->value] = $archived;
                $progress($step, sprintf('%s: %d files, %d bytes%s', $source->component->value, $archived->files, $archived->bytes, $archived->skippedLinks > 0 ? sprintf(', %d symbolic link(s) skipped', $archived->skippedLinks) : '').($archived->sourceMissing ? ' (directory does not exist)' : ''));
            }

            $step = BackupStep::Checksums;
            $progress($step, '');
            $payloads = $this->payloads($plan->partialDirectory);
            $progress($step, sprintf('%d payload file(s)', \count($payloads)));

            $step = BackupStep::Completion;
            $progress($step, '');
            $manifest = new BackupManifest(
                id: basename($plan->backupDirectory),
                type: $plan->type,
                createdAt: $plan->createdAt,
                appVersion: $this->appVersion,
                environment: $this->environment,
                database: $database,
                files: $files,
                payloads: $payloads,
            );
            $manifestFile = Path::join($plan->partialDirectory, BackupFormat::MANIFEST);
            $filesystem->dumpFile($manifestFile, $manifest->toJson());
            $filesystem->chmod($manifestFile, 0o600);

            if (file_exists($plan->backupDirectory)) {
                throw new BackupFailed(sprintf('%s appeared while the backup was written.', $plan->backupDirectory), 'Run the backup again.');
            }
            $filesystem->rename($plan->partialDirectory, $plan->backupDirectory);
            $progress($step, $plan->backupDirectory);

            return $manifest;
        } catch (\Throwable $exception) {
            if ($createdPartial) {
                $filesystem->remove($plan->partialDirectory);
            }

            if ($exception instanceof BackupFailed) {
                throw $exception->inStep($step);
            }

            throw new BackupFailed(sprintf('Unexpected error: %s', $exception->getMessage()), 'Run the command again with -vvv for details.', $step, $exception);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param callable(BackupStep, string): void $progress
     */
    private function preflight(BackupPlan $plan, callable $progress): void
    {
        $filesystem = new Filesystem();

        if (!is_dir($plan->targetDirectory)) {
            $filesystem->mkdir($plan->targetDirectory, 0o700);
        }
        if (!is_writable($plan->targetDirectory)) {
            throw new BackupFailed(sprintf('The backup directory %s is not writable.', $plan->targetDirectory), 'Check the permissions of the directory or choose another one with --target-dir.');
        }
        if (file_exists($plan->backupDirectory)) {
            throw new BackupFailed(sprintf('A backup named %s already exists.', basename($plan->backupDirectory)), 'Wait a second and run the command again; existing backups are never overwritten.');
        }
        if (file_exists($plan->partialDirectory)) {
            throw new BackupFailed(sprintf('An unfinished backup %s is in the way.', basename($plan->partialDirectory)), 'It is left over from an interrupted run and is not a usable backup; remove it and run the command again.');
        }

        $progress(BackupStep::Preflight, $this->dumper->preflight());
        $this->archiver->preflight();
    }

    /**
     * @return array<string, array{bytes: int, sha256: string}>
     */
    private function payloads(string $directory): array
    {
        $payloads = [];
        $candidates = [BackupFormat::DATABASE_DUMP];
        foreach (BackupComponent::fileComponents() as $component) {
            $candidates[] = BackupFormat::archivePath($component);
            $candidates[] = BackupFormat::checksumListPath($component);
        }

        foreach ($candidates as $relative) {
            $path = Path::join($directory, $relative);
            if (!is_file($path)) {
                continue;
            }

            chmod($path, 0o600);
            $hash = hash_file('sha256', $path);
            $bytes = filesize($path);
            if (false === $hash || false === $bytes) {
                throw new BackupFailed(sprintf('Could not read %s to compute its checksum.', $relative));
            }

            $payloads[$relative] = ['bytes' => $bytes, 'sha256' => $hash];
        }

        ksort($payloads, \SORT_STRING);

        return $payloads;
    }
}
