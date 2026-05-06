<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Command;

use App\LegacyMigration\Infrastructure\Doctrine\LegacyMigrationSchemaManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:legacy-migration:uninstall',
    description: 'Drop legacy migration tables from default database.',
)]
final class LegacyMigrationUninstallCommand extends Command
{
    public function __construct(
        private readonly LegacyMigrationSchemaManager $schemaManager,
        private readonly Connection $defaultConnection,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Delete without confirmation and ignore failed/running safety gate.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        if ($this->schemaManager->isInstalled()) {
            $io->table(['Metric', 'Value'], [
                ['Mapped users', (string) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM legacy_migration_user_mapping')],
                ['Mapped hospitals', (string) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM legacy_migration_hospital_mapping')],
                ['Mapped imports', (string) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM legacy_migration_import_mapping')],
                ['Migrated allocations', (string) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM legacy_migration_allocation_mapping')],
                ['Running/failed imports', (string) $this->defaultConnection->fetchOne("SELECT COUNT(*) FROM legacy_migration_import_mapping WHERE status IN ('running', 'failed')")],
            ]);
        }

        if (!$force && !$io->confirm('Delete all legacy migration tables from allowlist?', false)) {
            $io->warning('Aborted.');

            return Command::SUCCESS;
        }

        $this->schemaManager->uninstall($force);
        $io->success('Legacy migration tables removed.');

        return Command::SUCCESS;
    }
}

