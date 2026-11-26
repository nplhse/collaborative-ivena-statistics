<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Import\EncounterSourceFileRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual run of the daily encounter upload file retention (#1049). Prints import ids only. A manual run
 * is not recorded as a job run; the scheduled run is (ADR 019).
 */
#[AsCommand(
    name: 'app:encounter:delete-expired-source-files',
    description: 'Delete encounter upload files older than the retention period (app.encounter.source_file_retention_days).',
)]
final readonly class DeleteExpiredEncounterSourceFilesCommand
{
    public function __construct(
        private EncounterSourceFileRetention $retention,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'List the files that would be deleted without deleting them')]
        bool $dryRun = false,
    ): int {
        $result = $this->retention->run($dryRun);

        $io->text(\sprintf(
            'Retention: %d day(s); files uploaded before %s are deleted.',
            $this->retention->retentionDays(),
            $result->cutoff->format('Y-m-d H:i:s T'),
        ));
        if ($dryRun) {
            $io->note('Dry run: no file is deleted and no import is changed.');
        }

        $rows = [];
        foreach ([[$result->deleted, $dryRun ? 'would delete' : 'deleted'], [$result->missing, $dryRun ? 'already gone, would mark' : 'already gone, marked'], [$result->failed, 'deletion failed']] as [$ids, $label]) {
            foreach ($ids as $id) {
                $rows[] = [$id, $label];
            }
        }

        if ([] === $rows) {
            $io->success('No encounter upload file is past the retention period.');

            return Command::SUCCESS;
        }

        $io->table(['Import', 'Result'], $rows);

        if ([] !== $result->failed) {
            $io->error(\sprintf('%d file(s) could not be deleted; see the import log. The next run tries again.', \count($result->failed)));

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d file(s) %s, %d import(s) without a file %s.',
            \count($result->deleted),
            $dryRun ? 'would be deleted' : 'deleted',
            \count($result->missing),
            $dryRun ? 'would be marked' : 'marked',
        ));

        return Command::SUCCESS;
    }
}
