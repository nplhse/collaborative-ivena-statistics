<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Backup\ArchivedFiles;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\FileComponentSource;
use App\Storage\Application\Contract\FileArchiverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Packs one file component with the system tar (ADR 038, "Files").
 *
 * The regular files are listed and hashed first; tar then reads exactly that list (`-T`, entries as `./path`,
 * portable between GNU tar and bsdtar). Afterwards size and modification time of every file are checked again:
 * a file that changed while it was packed fails the backup instead of producing an inconsistent archive.
 * Symbolic links are never followed or packed. The SHA-256 list uses the `sha256sum` format, so an operator
 * can also check extracted files by hand with `sha256sum -c`.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for FileArchiverInterface.
 */
#[AsAlias(FileArchiverInterface::class)]
final readonly class TarFileArchiver implements FileArchiverInterface
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    #[\Override]
    public function preflight(): void
    {
        $process = new Process(['tar', '--version'], $this->projectDir);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new BackupFailed('tar could not be run.', 'Install tar (GNU tar or bsdtar) on PATH.');
        }
    }

    #[\Override]
    public function archive(FileComponentSource $source, string $archiveFile, string $checksumFile): ArchivedFiles
    {
        if (!is_dir($source->path)) {
            return new ArchivedFiles(0, 0, 0, sourceMissing: true);
        }

        $root = realpath($source->path);
        if (false === $root) {
            throw new BackupFailed(sprintf('The %s directory cannot be resolved.', $source->component->value));
        }

        [$files, $skippedLinks] = $this->collect($root, $source);
        if ([] === $files) {
            return new ArchivedFiles(0, 0, $skippedLinks);
        }

        $listFile = $archiveFile.'.list';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($listFile, implode("\n", array_map(static fn (string $relative): string => './'.$relative, array_keys($files)))."\n");

        try {
            $process = new Process(['tar', '-czf', $archiveFile, '-C', $root, '-T', $listFile], $this->projectDir);
            $process->setTimeout(null);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new BackupFailed(sprintf('tar failed for %s with exit code %d: %s', $source->component->value, (int) $process->getExitCode(), mb_substr(trim($process->getErrorOutput()), 0, 500)), 'Check the permissions of the directory and that no process changes its files during the backup.');
            }
        } finally {
            $filesystem->remove($listFile);
        }

        clearstatcache();
        $bytes = 0;
        $checksums = '';
        foreach ($files as $relative => $file) {
            $path = $root.'/'.$relative;
            $stat = @stat($path);
            if (false === $stat || $stat['size'] !== $file['size'] || $stat['mtime'] !== $file['mtime']) {
                throw new BackupFailed(sprintf('%s/%s changed while it was backed up.', $source->component->value, $relative), 'Stop imports and uploads (or wait until they are done) and run the backup again.');
            }

            $bytes += $file['size'];
            $checksums .= $file['sha256'].'  '.$relative."\n";
        }

        $filesystem->dumpFile($checksumFile, $checksums);

        return new ArchivedFiles(\count($files), $bytes, $skippedLinks);
    }

    /**
     * @return array{0: array<string, array{size: int, mtime: int, sha256: string}>, 1: int} files by relative path
     *                                                                                       (sorted) and skipped links
     */
    private function collect(string $root, FileComponentSource $source): array
    {
        $files = [];
        $skippedLinks = 0;

        $directories = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO);
        $filter = new \RecursiveCallbackFilterIterator(
            $directories,
            static fn (mixed $entry): bool => !$entry instanceof \SplFileInfo || $entry->getPath() !== $root || !\in_array($entry->getFilename(), $source->excluded, true),
        );

        try {
            foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if (!$entry instanceof \SplFileInfo) {
                    continue;
                }
                if ($entry->isLink()) {
                    ++$skippedLinks;

                    continue;
                }
                if (!$entry->isFile()) {
                    continue;
                }

                $relative = substr($entry->getPathname(), \strlen($root) + 1);
                if (1 === preg_match('/[\r\n]/', $relative)) {
                    throw new BackupFailed(sprintf('A file name in %s contains a line break.', $source->component->value), 'Rename or remove the file and run the backup again.');
                }

                $stat = @stat($entry->getPathname());
                $hash = @hash_file('sha256', $entry->getPathname());
                if (false === $stat || false === $hash) {
                    throw new BackupFailed(sprintf('%s/%s cannot be read.', $source->component->value, $relative), 'Check the file permissions.');
                }

                $files[$relative] = ['size' => $stat['size'], 'mtime' => $stat['mtime'], 'sha256' => $hash];
            }
        } catch (\UnexpectedValueException $exception) {
            throw new BackupFailed(sprintf('The %s directory cannot be read completely.', $source->component->value), 'Check the directory permissions.', previous: $exception);
        }

        ksort($files, \SORT_STRING);

        return [$files, $skippedLinks];
    }
}
