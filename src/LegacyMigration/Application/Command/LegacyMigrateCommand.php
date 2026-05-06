<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Command;

use App\LegacyMigration\Application\Service\LegacyMigrationOrchestrator;
use App\LegacyMigration\Infrastructure\Doctrine\LegacyMigrationSchemaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:legacy-migration:migrate',
    description: 'Run migration from legacy database into current app.',
)]
final class LegacyMigrateCommand extends Command
{
    public function __construct(
        private readonly LegacyMigrationSchemaManager $schemaManager,
        private readonly LegacyMigrationOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size for allocations.', '2500')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'users|hospitals|imports|allocations|all', 'all')
            ->addOption('legacy-import-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('resume', null, InputOption::VALUE_NONE)
            ->addOption('force', null, InputOption::VALUE_NONE)
            ->addOption('no-progress', null, InputOption::VALUE_NONE);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $only = (string) $input->getOption('only');
        $legacyImportId = null !== $input->getOption('legacy-import-id') ? (int) $input->getOption('legacy-import-id') : null;
        $resume = (bool) $input->getOption('resume');
        $noProgress = (bool) $input->getOption('no-progress');

        $allowed = ['users', 'hospitals', 'imports', 'allocations', 'all'];
        if (!\in_array($only, $allowed, true)) {
            $io->error(sprintf('--only must be one of: %s', implode(', ', $allowed)));

            return Command::INVALID;
        }

        try {
            $this->schemaManager->assertInstalled();
            $this->orchestrator->assertLegacyConnectionReady();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->note(sprintf('Starting legacy migration (%s).', $dryRun ? 'dry-run' : 'write mode'));

        try {
            $results = $this->orchestrator->migrate(
                dryRun: $dryRun,
                batchSize: $batchSize,
                only: $only,
                legacyImportId: $legacyImportId,
                resume: $resume,
                withProgress: !$noProgress && $output->isDecorated(),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Legacy migration finished.');
        $io->table(['Phase', 'Migrated'], [
            ['Users', (string) $results['users']],
            ['Hospitals', (string) $results['hospitals']],
            ['Imports', (string) $results['imports']],
            ['Allocations', (string) $results['allocations']],
        ]);

        return Command::SUCCESS;
    }
}

