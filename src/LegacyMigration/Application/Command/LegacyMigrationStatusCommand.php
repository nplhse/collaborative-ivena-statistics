<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Command;

use App\LegacyMigration\Infrastructure\Doctrine\LegacyMigrationSchemaManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @psalm-suppress UnusedClass */
#[AsCommand(
    name: 'app:legacy-migration:status',
    description: 'Show current legacy migration status.',
)]
final class LegacyMigrationStatusCommand extends Command
{
    public function __construct(
        private readonly LegacyMigrationSchemaManager $schemaManager,
        private readonly Connection $defaultConnection,
        private readonly Connection $legacyConnection,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $status = $this->schemaManager->getStatus();

        $io->title('Legacy migration status');
        $io->table(['Metric', 'Value'], [
            ['Installed', $status->installed ? 'yes' : 'no'],
            ['User mappings', (string) $status->userMappings],
            ['Hospital mappings', (string) $status->hospitalMappings],
            ['Import mappings', (string) $status->importMappings],
            ['Allocation mappings', (string) $status->allocationMappings],
            ['Sum migrated_count', (string) $status->migratedCountSum],
            ['Last error', $status->lastErrorMessage ?? '-'],
        ]);

        if (!$status->installed) {
            return Command::SUCCESS;
        }

        $statusRows = [];
        foreach (['pending', 'running', 'done', 'failed'] as $key) {
            $statusRows[] = [$key, (string) ($status->importStatusCounts[$key] ?? 0)];
        }
        $io->section('Import status counts');
        $io->table(['Status', 'Count'], $statusRows);

        $perImportRows = $this->defaultConnection->fetchAllAssociative(
            'SELECT legacy_import_id, last_allocation_id, migrated_count, status, error_message FROM legacy_migration_import_mapping ORDER BY legacy_import_id ASC LIMIT 25'
        );
        $io->section('Last allocation checkpoint per import (first 25)');
        $io->table(['Legacy import', 'Last allocation id', 'Migrated', 'Status', 'Error'], array_map(
            static fn (array $r): array => [
                (string) $r['legacy_import_id'],
                (string) ($r['last_allocation_id'] ?? '-'),
                (string) $r['migrated_count'],
                (string) $r['status'],
                (string) ($r['error_message'] ?? '-'),
            ],
            $perImportRows
        ));

        $doneImports = $status->importStatusCounts['done'] ?? 0;
        try {
            $legacyTotalImports = (int) $this->legacyConnection->fetchOne('SELECT COUNT(*) FROM import');
            $progress = $legacyTotalImports > 0
                ? round(((float) $doneImports / (float) $legacyTotalImports) * 100.0, 2)
                : 0.0;
            $io->writeln(sprintf('Estimated progress by imports: %s%% (%d/%d)', $progress, $doneImports, $legacyTotalImports));
        } catch (\Throwable) {
            $io->writeln('Estimated progress by imports: n/a (legacy table import is not readable).');
        }

        return Command::SUCCESS;
    }
}
