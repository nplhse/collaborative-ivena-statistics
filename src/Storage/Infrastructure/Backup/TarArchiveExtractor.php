<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Contract\ArchiveExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Process\Process;

/**
 * Extracts a component archive with the system tar (ADR 038, "Restore").
 *
 * Only archives that passed BackupVerifier reach this class, so every entry is a regular file or directory with a
 * relative path. Ownership is not restored; files belong to the user running the restore.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for ArchiveExtractorInterface.
 */
#[AsAlias(ArchiveExtractorInterface::class)]
final readonly class TarArchiveExtractor implements ArchiveExtractorInterface
{
    #[\Override]
    public function extract(string $archiveFile, string $targetDirectory): void
    {
        $process = new Process(['tar', '-xzf', $archiveFile, '-C', $targetDirectory, '--no-same-owner'], $targetDirectory);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new BackupFailed(sprintf('tar could not extract %s (exit code %d): %s', basename($archiveFile), (int) $process->getExitCode(), mb_substr(trim($process->getErrorOutput()), 0, 500)), 'Check the free disk space and the permissions of the target directory.');
        }
    }
}
