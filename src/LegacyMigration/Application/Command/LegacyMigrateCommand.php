<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Command;

use App\LegacyMigration\Application\Contract\NullLegacyMigrationProgress;
use App\LegacyMigration\Application\Exception\LegacyMigrationInterruptedException;
use App\LegacyMigration\Application\Service\LegacyMigrationConsoleProgress;
use App\LegacyMigration\Application\Service\LegacyMigrationInterruptHandler;
use App\LegacyMigration\Application\Service\LegacyMigrationOrchestrator;
use App\LegacyMigration\Application\Service\LegacyMigrationProgressReporter;
use App\LegacyMigration\Application\Service\LegacyMigrationRunControl;
use App\LegacyMigration\Infrastructure\Doctrine\LegacyMigrationSchemaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @psalm-suppress UnusedClass */
#[AsCommand(
    name: 'app:legacy-migration:migrate',
    description: 'Run migration from legacy database into current app. Exit codes: 0=success, 1=failure/interrupted, 2=invalid options. Requires ext-pcntl for graceful SIGINT/SIGTERM handling.',
)]
final class LegacyMigrateCommand extends Command implements SignalableCommandInterface
{
    private ?LegacyMigrationRunControl $runControl = null;

    public function __construct(
        private readonly LegacyMigrationSchemaManager $schemaManager,
        private readonly LegacyMigrationOrchestrator $orchestrator,
        private readonly LegacyMigrationInterruptHandler $interruptHandler,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size for allocations.', '500')
            ->addOption('skip-projection', null, InputOption::VALUE_NONE, 'Skip allocation stats projection rebuild after each import.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'users|hospitals|imports|allocations|all', 'all')
            ->addOption('legacy-import-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('resume', null, InputOption::VALUE_NONE)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Include completed imports (status=done) in allocation migration.')
            ->addOption('no-progress', null, InputOption::VALUE_NONE);
    }

    /**
     * @return list<int>
     */
    #[\Override]
    public function getSubscribedSignals(): array
    {
        if (!\function_exists('pcntl_signal')) {
            return [];
        }

        return [\SIGINT, \SIGTERM];
    }

    #[\Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->runControl?->requestStop($signal);

        return false;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $only = $input->getOption('only');
        $legacyImportId = null !== $input->getOption('legacy-import-id') ? (int) $input->getOption('legacy-import-id') : null;
        $resume = $input->getOption('resume');
        $noProgress = $input->getOption('no-progress');
        $skipProjection = $input->getOption('skip-projection');
        $force = $input->getOption('force');

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

        $this->runControl = new LegacyMigrationRunControl();

        $progress = $noProgress
            ? new NullLegacyMigrationProgress()
            : new LegacyMigrationConsoleProgress($io, new LegacyMigrationProgressReporter());

        try {
            $results = $this->orchestrator->migrate(
                dryRun: $dryRun,
                batchSize: $batchSize,
                only: $only,
                legacyImportId: $legacyImportId,
                resume: $resume,
                progress: $progress,
                runControl: $this->runControl,
                skipProjection: $skipProjection,
                force: $force,
            );
        } catch (LegacyMigrationInterruptedException $e) {
            if (!$dryRun) {
                $this->interruptHandler->handle($e, $legacyImportId);
            }
            $io->warning(sprintf('Migration interrupted (signal %d). Re-run with the same options to resume.', $e->getSignal()));

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->runControl = null;
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
