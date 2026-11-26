<?php

declare(strict_types=1);

namespace App\Storage\UI\Console\Command;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupLocator;
use App\Storage\Application\Backup\BackupRestorer;
use App\Storage\Application\Backup\RestoreStep;
use App\Storage\Application\Backup\RestoreTarget;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Restores a full backup: replaces the database and the file directories (ADR 038, "Restore").
 *
 * Shows what will be replaced, verifies the backup and asks before it starts. Unattended runs must pass
 * --confirm-destructive. Exits with 1 when the restore failed; the error says what was left changed.
 */
#[AsCommand(
    name: 'app:backup:restore',
    description: 'Restore a full backup: replaces the database and the file directories.',
)]
final readonly class RestoreBackupCommand
{
    public function __construct(
        private BackupLocator $locator,
        private BackupRestorer $restorer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'Backup directory, or the name of a backup in BACKUP_DIR (default: the newest backup)')]
        ?string $backup = null,
        #[Option(description: 'Restore without asking (required with --no-interaction)')]
        bool $confirmDestructive = false,
        #[Option(description: 'Keep the current database and directories until the restore is complete, so a failure is undone (needs space for a second copy)')]
        bool $keepPrevious = false,
    ): int {
        try {
            $plan = $this->restorer->plan($this->locator->resolve($backup), $keepPrevious);
        } catch (BackupFailed $failure) {
            return $this->fail($io, $failure);
        }

        $manifest = $plan->manifest;
        $io->title('Restore backup');
        $io->definitionList(
            ['Backup' => $plan->backupDirectory],
            ['Created' => sprintf('%s with %s (%s)', $manifest->createdAt->format('Y-m-d H:i:s \U\T\C'), $manifest->appVersion, $manifest->environment)],
            ['Database' => sprintf('%s: replaced by %d tables with %s rows', $plan->database, \count($manifest->database->tableRows), number_format(array_sum($manifest->database->tableRows)))],
            ['Migrations' => $plan->migrations->isOlder() ? sprintf('%d behind this application', \count($plan->migrations->pending)) : 'same as this application'],
            ...array_map(static fn (RestoreTarget $target): array => [$target->component->value => sprintf('%s: %s %d files', $target->path, $target->exists ? 'replaced by' : 'created with', $target->archived->files)], $plan->targets),
        );
        foreach ($plan->warnings as $warning) {
            $io->warning($warning);
        }

        $io->caution($plan->keepPrevious ? [
            'The current database and file directories are replaced. Stop the Messenger worker first.',
            'They are kept until the restore is complete, so a failure is undone; this needs space for a second copy. Afterwards the previous database is dropped and the previous directories are kept next to the restored ones.',
        ] : [
            'The current database and file directories are replaced. Stop the Messenger worker first.',
            'The current database is dropped before the backup is restored: if the restore fails, it is gone. Create a backup first if you may need the current state again (or use --keep-previous when there is space for a second copy).',
        ]);

        if (!$confirmDestructive) {
            if (!$input->isInteractive()) {
                $io->error('Unattended restores need --confirm-destructive. Nothing was changed.');

                return Command::FAILURE;
            }
            if (!$io->confirm(sprintf('Replace the database and files with backup %s?', $manifest->id), false)) {
                $io->note('Nothing was changed.');

                return Command::SUCCESS;
            }
        }

        try {
            $result = $this->restorer->restore($plan, static function (RestoreStep $step, ?string $detail, bool $ok) use ($io): void {
                if (null === $detail) {
                    $io->section($step->label());

                    return;
                }

                $io->writeln(($ok ? ' ✔ ' : ' <error>✘</error> ').$detail);
            });
        } catch (BackupFailed $failure) {
            return $this->fail($io, $failure);
        }

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }

        $io->success(sprintf('Backup %s restored.', $manifest->id));
        $io->listing(array_filter([
            $result->pendingMigrations > 0 ? sprintf('Run "php bin/console doctrine:migrations:migrate": %d migration(s) are newer than the backup.', $result->pendingMigrations) : null,
            'Run "php bin/console cache:clear" and start the Messenger worker again.',
            [] === $result->previousDirectories ? null : sprintf('Remove the replaced directories once the application works: %s', implode(', ', $result->previousDirectories)),
        ]));

        return Command::SUCCESS;
    }

    private function fail(SymfonyStyle $io, BackupFailed $failure): int
    {
        $io->error(array_filter([
            sprintf('Restore failed%s: %s', $failure->step instanceof RestoreStep ? ' during "'.$failure->step->label().'"' : '', $failure->getMessage()),
            $failure->hint,
            $failure->state ?? 'Nothing was changed.',
        ]));

        if ($io->isVeryVerbose() && $failure->getPrevious() instanceof \Throwable) {
            $io->writeln((string) $failure->getPrevious());
        }

        return Command::FAILURE;
    }
}
