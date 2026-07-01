<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Indication\BackfillAllocationIndicationNormalizedService;
use App\Allocation\UI\Console\Input\BackfillAllocationIndicationNormalizedInput;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:allocation:backfill-indications',
    description: 'Repair/sync indication_raw.normalized from target and copy normalized IDs onto allocations (optional projection rebuild). Not for routine use.',
)]
final readonly class BackfillAllocationIndicationNormalizedCommand
{
    private const int GC_EVERY_N_IMPORTS = 25;

    public function __construct(
        private BackfillAllocationIndicationNormalizedService $backfillService,
        private AllocationStatsProjectionRebuildInterface $projectionRebuilder,
        private Connection $connection,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[MapInput] BackfillAllocationIndicationNormalizedInput $input,
    ): int {
        $dryRun = $input->dryRun;
        $syncRaw = !$input->skipRawSync;
        $backfillAllocations = !$input->skipAllocations;
        $rebuildProjection = $input->rebuildProjection;

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
            $io->writeln('<comment>Projection not rebuilt. Use --rebuild-projection or <info>app:statistics:rebuild-projection</info> so Top indications reflect the backfill.</comment>');
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
