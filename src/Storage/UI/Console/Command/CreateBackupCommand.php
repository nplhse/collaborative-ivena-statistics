<?php

declare(strict_types=1);

namespace App\Storage\UI\Console\Command;

use App\Storage\Application\Backup\BackupCreator;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupStep;
use App\Storage\Application\Backup\FileComponentSource;
use App\Storage\Application\ByteFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates a full backup with manifest (ADR 038, "Create"). Shows the plan first; interactive runs ask before
 * they start, unattended runs (--no-interaction, cron) start right away. Exits with 1 when the backup failed;
 * a failed run leaves no backup behind.
 */
#[AsCommand(
    name: 'app:backup:create',
    description: 'Create a full backup (database and files) with a manifest and checksums.',
)]
final readonly class CreateBackupCommand
{
    public function __construct(
        private BackupCreator $creator,
        #[Autowire(param: 'app.backup.directory')]
        private string $defaultDirectory,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option(description: 'Directory that receives the backup (default: BACKUP_DIR or var/backups)')]
        ?string $targetDir = null,
    ): int {
        try {
            $plan = $this->creator->plan($targetDir ?? $this->defaultDirectory);
        } catch (BackupFailed $failure) {
            return $this->fail($io, $failure, false);
        }

        $io->title('Create backup');
        $io->definitionList(
            ['Type' => $plan->type],
            ['Backup' => $plan->backupDirectory],
            ['Database' => $plan->database],
            ...array_map(static fn (FileComponentSource $source): array => [$source->component->value => $source->path.([] === $source->excluded ? '' : sprintf(' (without %s)', implode(', ', $source->excluded)))], $plan->fileSources),
        );
        foreach ($plan->warnings as $warning) {
            $io->warning($warning);
        }

        if ($input->isInteractive() && !$io->confirm('Start the backup?', true)) {
            $io->note('Nothing was written.');

            return Command::SUCCESS;
        }

        try {
            $manifest = $this->creator->create($plan, static function (BackupStep $step, string $detail) use ($io): void {
                if ('' === $detail) {
                    $io->section($step->label());

                    return;
                }

                $io->writeln(' ✔ '.$detail);
            });
        } catch (BackupFailed $failure) {
            return $this->fail($io, $failure, true);
        }

        $bytes = array_sum(array_map(static fn (array $payload): int => $payload['bytes'], $manifest->payloads));
        $io->success(sprintf('Backup %s created (%s).', $manifest->id, ByteFormatter::format($bytes)));

        return Command::SUCCESS;
    }

    private function fail(SymfonyStyle $io, BackupFailed $failure, bool $started): int
    {
        $io->error(array_filter([
            sprintf('Backup failed%s: %s', $failure->step instanceof BackupStep ? ' during "'.$failure->step->label().'"' : '', $failure->getMessage()),
            $failure->hint,
            $started ? 'No backup was created. The database and the application files were not changed; unfinished backup files of this run were removed.' : null,
        ]));

        if ($io->isVeryVerbose() && $failure->getPrevious() instanceof \Throwable) {
            $io->writeln((string) $failure->getPrevious());
        }

        return Command::FAILURE;
    }
}
