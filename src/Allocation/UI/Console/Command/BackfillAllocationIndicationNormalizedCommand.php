<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Indication\BackfillAllocationIndicationNormalizedService;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:allocation:backfill-indications',
    description: 'Sync indication_raw.normalized from target and copy normalized IDs onto allocations (optional projection rebuild).',
)]
final class BackfillAllocationIndicationNormalizedCommand extends Command
{
    private const int GC_EVERY_N_IMPORTS = 25;

    public function __construct(
        private readonly BackfillAllocationIndicationNormalizedService $backfillService,
        private readonly AllocationStatsProjectionRebuildInterface $projectionRebuilder,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report how many rows would change')
            ->addOption('skip-raw-sync', null, InputOption::VALUE_NONE, 'Do not copy indication_raw.target_id to normalized_id')
            ->addOption('skip-allocations', null, InputOption::VALUE_NONE, 'Do not update allocation indication columns')
            ->addOption('rebuild-projection', null, InputOption::VALUE_NONE, 'Rebuild allocation_stats_projection per import after backfill');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $syncRaw = !(bool) $input->getOption('skip-raw-sync');
        $backfillAllocations = !(bool) $input->getOption('skip-allocations');
        $rebuildProjection = (bool) $input->getOption('rebuild-projection');

        $io->title('Backfill allocation indication_normalized');

        if ($dryRun) {
            $io->note('Dry run: no rows will be written.');
        }

        $startedAt = microtime(true);
        $result = $this->backfillService->run($dryRun, $syncRaw, $backfillAllocations);

        $io->table(
            ['Step', 'Rows'],
            [
                ['indication_raw.normalized_id ← target_id', (string) $result->rawNormalizedSyncedFromTarget],
                ['allocation.indication_normalized_id', (string) $result->allocationsPrimaryUpdated],
                ['allocation.secondary_indication_normalized_id', (string) $result->allocationsSecondaryUpdated],
            ],
        );

        if ($dryRun) {
            $io->success('Dry run finished. Re-run without --dry-run to apply changes.');

            return Command::SUCCESS;
        }

        if ($rebuildProjection) {
            $importIds = $this->fetchImportIdsFromAllocations();
            if ([] === $importIds) {
                $io->warning('No allocations found; projection rebuild skipped.');
            } else {
                $io->section(sprintf('Rebuilding allocation_stats_projection for %d import(s)', \count($importIds)));
                $progress = new ProgressBar($output, \count($importIds));
                $progress->start();

                foreach ($importIds as $index => $importId) {
                    $this->projectionRebuilder->rebuildForImport($importId);
                    $progress->advance();

                    if (0 === (($index + 1) % self::GC_EVERY_N_IMPORTS)) {
                        gc_collect_cycles();
                    }
                }

                $progress->finish();
                $output->writeln('');
            }
        } else {
            $io->writeln('<comment>Projection not rebuilt. Use --rebuild-projection or <info>app:seed:projection</info> so Top diagnoses reflect the backfill.</comment>');
        }

        $io->success(sprintf('Backfill finished in %.2fs.', microtime(true) - $startedAt));

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function fetchImportIdsFromAllocations(): array
    {
        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn('SELECT DISTINCT import_id FROM allocation ORDER BY import_id ASC');

        return array_map(static fn (int|string $value): int => (int) $value, $rows);
    }
}
