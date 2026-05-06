<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Command;

use App\LegacyMigration\Infrastructure\Doctrine\LegacyMigrationSchemaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @psalm-suppress UnusedClass */
#[AsCommand(
    name: 'app:legacy-migration:install',
    description: 'Install legacy migration tables in default database.',
)]
final class LegacyMigrationInstallCommand extends Command
{
    public function __construct(
        private readonly LegacyMigrationSchemaManager $schemaManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->schemaManager->install();
        $status = $this->schemaManager->getStatus();

        $io->success('Legacy migration tables are installed.');
        $io->table(['Metric', 'Value'], [
            ['Installed', $status->installed ? 'yes' : 'no'],
            ['User mappings', (string) $status->userMappings],
            ['Hospital mappings', (string) $status->hospitalMappings],
            ['Import mappings', (string) $status->importMappings],
            ['Allocation mappings', (string) $status->allocationMappings],
        ]);

        return Command::SUCCESS;
    }
}
