<?php

declare(strict_types=1);

namespace App\Storage\UI\Console\Command;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupLocator;
use App\Storage\Application\Backup\BackupVerifier;
use App\Storage\Application\Backup\VerificationStep;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies a backup without restoring it (ADR 038, "Verify"). Read-only; exits with 1 when the backup has a
 * problem or cannot be verified, so it can follow app:backup:create in cron.
 */
#[AsCommand(
    name: 'app:backup:verify',
    description: 'Verify a backup: manifest, checksums, database dump and file archives.',
)]
final readonly class VerifyBackupCommand
{
    public function __construct(
        private BackupLocator $locator,
        private BackupVerifier $verifier,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Backup directory, or the name of a backup in BACKUP_DIR (default: the newest backup)')]
        ?string $backup = null,
    ): int {
        try {
            $directory = $this->locator->resolve($backup);
            $io->title('Verify backup');
            $io->definitionList(['Backup' => $directory]);

            $verification = $this->verifier->verify($directory, static function (VerificationStep $step, ?string $detail, bool $ok) use ($io): void {
                if (null === $detail) {
                    $io->section($step->label());

                    return;
                }

                $io->writeln(($ok ? ' ✔ ' : ' <error>✘</error> ').$detail);
            });
        } catch (BackupFailed $failure) {
            $io->error(array_filter([sprintf('The backup cannot be verified: %s', $failure->getMessage()), $failure->hint]));

            return Command::FAILURE;
        }

        foreach ($verification->warnings as $warning) {
            $io->warning($warning);
        }

        if (!$verification->isValid()) {
            $io->error([
                sprintf('Backup %s has %d %s and must not be used for a restore:', $verification->manifest->id, \count($verification->problems), 1 === \count($verification->problems) ? 'problem' : 'problems'),
                ...array_map(static fn (string $problem): string => '- '.$problem, $verification->problems),
            ]);

            return Command::FAILURE;
        }

        $io->success(sprintf('Backup %s is complete and readable.', $verification->manifest->id));

        return Command::SUCCESS;
    }
}
