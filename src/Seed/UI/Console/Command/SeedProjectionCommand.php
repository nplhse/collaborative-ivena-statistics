<?php

declare(strict_types=1);

namespace App\Seed\UI\Console\Command;

use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:projection',
    description: 'Rebuild allocation_stats_projection from existing allocations.',
)]
final class SeedProjectionCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly AllocationStatsProjectionRebuildInterface $rebuilder,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding allocation_stats_projection');

        $startedAt = microtime(true);

        try {
            $this->truncateProjectionTable();
            $io->writeln('<info>Projection table truncated.</info>');

            $importIds = $this->fetchImportIdsFromAllocations();
            if ([] === $importIds) {
                $io->success('No allocations found. Projection table remains empty.');

                return Command::SUCCESS;
            }

            $progress = new ProgressBar($output, \count($importIds));
            $progress->start();

            foreach ($importIds as $importId) {
                $this->rebuilder->rebuildForImport($importId);
                $progress->advance();
            }

            $progress->finish();
            $output->writeln('');
        } catch (\Throwable $e) {
            $io->error(sprintf('Projection seed failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $durationSeconds = microtime(true) - $startedAt;
        $io->success(sprintf(
            'Projection rebuild finished. Imports processed: %d (%.2fs).',
            \count($importIds),
            $durationSeconds,
        ));

        return Command::SUCCESS;
    }

    private function truncateProjectionTable(): void
    {
        $this->connection->executeStatement('TRUNCATE allocation_stats_projection RESTART IDENTITY');
    }

    /**
     * @return list<int>
     */
    private function fetchImportIdsFromAllocations(): array
    {
        /** @var list<int|string> $rows */
        $rows = $this->entityManager
            ->getConnection()
            ->fetchFirstColumn('SELECT DISTINCT import_id FROM allocation ORDER BY import_id ASC');

        return array_map(static fn (int|string $value): int => (int) $value, $rows);
    }
}
