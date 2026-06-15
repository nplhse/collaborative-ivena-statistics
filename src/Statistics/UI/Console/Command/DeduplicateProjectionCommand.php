<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Command;

use App\Statistics\Application\Projection\AllocationProjectionDeduplicator;
use App\Statistics\Application\Projection\DeduplicationProgressCallback;
use App\Statistics\Application\Projection\Dto\DeduplicationReport;
use App\Statistics\Application\Projection\Dto\DeduplicationStrategySummary;
use App\Statistics\Infrastructure\MaterializedView\MaterializedViewRefresher;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:projection:deduplicate',
    description: 'Detect and remove duplicate allocation_stats_projection rows caused by duplicate allocations.',
)]
final class DeduplicateProjectionCommand extends Command
{
    private const int PROGRESS_BAR_THRESHOLD = 100;

    public function __construct(
        private readonly AllocationProjectionDeduplicator $deduplicator,
        private readonly MaterializedViewRefresher $materializedViewRefresher,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report duplicates without deleting (default)')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Delete duplicates, remove orphan projections, and refresh materialized views');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');
        $dryRun = !$execute || (bool) $input->getOption('dry-run');

        $io->title('Deduplicate allocation_stats_projection');

        if ($dryRun) {
            $io->note('Dry run: no rows will be deleted.');
        }

        $startedAt = microtime(true);
        $progress = new ConsoleDeduplicationProgress($output, $io, self::PROGRESS_BAR_THRESHOLD);

        try {
            if ($dryRun) {
                $report = $this->deduplicator->analyze($progress);
                $this->renderReport($io, $report);
                $io->success('Dry run finished. Re-run with --execute to apply changes.');

                return Command::SUCCESS;
            }

            $result = $this->deduplicator->execute($progress);
            $this->renderReport($io, $result->report);

            $io->section('Refreshing materialized views');
            $failed = false;
            foreach ($this->materializedViewRefresher->refresh([StatisticsMaterializedViewGroups::OVERVIEW]) as $refreshResult) {
                if ($refreshResult['success']) {
                    $io->writeln(sprintf(' <info>✓</info> %s', $refreshResult['view']));
                } else {
                    $failed = true;
                    $io->writeln(sprintf(' <error>✗</error> %s — %s', $refreshResult['view'], $refreshResult['message']));
                }
            }

            if ($failed) {
                $io->warning('Deduplication completed but some materialized view refreshes failed.');

                return Command::FAILURE;
            }

            $remainingAllocations = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM allocation');
            $durationSeconds = microtime(true) - $startedAt;

            $io->success(sprintf(
                'Deduplication finished in %.2fs. Removed %d projection row(s), %d allocation(s), %d assessment(s), %d orphan projection row(s). Remaining allocations: %d.',
                $durationSeconds,
                $result->deletedProjections,
                $result->deletedAllocations,
                $result->deletedAssessments,
                $result->deletedOrphanProjections,
                $remainingAllocations,
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Deduplication failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function renderReport(SymfonyStyle $io, DeduplicationReport $report): void
    {
        $io->section('Duplicate summary');

        $io->table(
            ['Strategy', 'Groups', 'Duplicate rows', 'Sample allocation IDs'],
            [
                $this->strategyTableRow($report->enr),
                $this->strategyTableRow($report->fingerprint),
            ],
        );

        $io->writeln(sprintf('Orphan projection rows: <info>%d</info>', $report->orphanProjectionRows));
        if ($report->enrHashGroupsSpanningMultipleYears > 0) {
            $io->writeln(sprintf(
                'ENR hash groups spanning multiple years (excluded from naive ENR-only match): <comment>%d</comment>',
                $report->enrHashGroupsSpanningMultipleYears,
            ));
        }
        $io->writeln(sprintf(
            'Total duplicate allocation rows to remove: <info>%d</info>',
            $report->totalDuplicateRows(),
        ));
    }

    /**
     * @return array{0: string, 1: int|string, 2: int|string, 3: string}
     */
    private function strategyTableRow(DeduplicationStrategySummary $summary): array
    {
        $sample = [] === $summary->sampleAllocationIds
            ? '—'
            : implode(', ', array_map(static fn (int $id): string => (string) $id, $summary->sampleAllocationIds));

        return [$summary->strategy, $summary->duplicateGroups, $summary->duplicateRows, $sample];
    }
}

/**
 * @internal console adapter for deduplication progress events
 */
final class ConsoleDeduplicationProgress implements DeduplicationProgressCallback
{
    private ?ProgressBar $progressBar = null;

    private string $currentPhase = '';

    public function __construct(
        private readonly OutputInterface $output,
        private readonly SymfonyStyle $io,
        private readonly int $progressBarThreshold,
    ) {
    }

    #[\Override]
    public function onProgress(string $phase, int $current, int $max, string $message): void
    {
        if ($phase !== $this->currentPhase) {
            $this->finishProgressBar();
            $this->currentPhase = $phase;
            $this->renderPhaseHeader($phase);
        }

        if ($this->shouldUseProgressBar($phase, $max)) {
            if (!$this->progressBar instanceof ProgressBar) {
                $this->progressBar = new ProgressBar($this->output, max(1, $max));
                $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
                $this->progressBar->setMessage($message);
                $this->progressBar->start();
            }

            $this->progressBar->setProgress($current);
            $this->progressBar->setMessage($message);

            if ($current >= $max) {
                $this->finishProgressBar();
            }

            return;
        }

        $this->io->writeln(sprintf('  %s', $message));
    }

    private function shouldUseProgressBar(string $phase, int $max): bool
    {
        if ($max <= 1 && AllocationProjectionDeduplicator::PHASE_DELETE_BATCH !== $phase) {
            return false;
        }

        if ($this->output->isVerbose()) {
            return true;
        }

        return AllocationProjectionDeduplicator::PHASE_DELETE_BATCH === $phase
            || $max >= $this->progressBarThreshold;
    }

    private function renderPhaseHeader(string $phase): void
    {
        $title = match ($phase) {
            AllocationProjectionDeduplicator::PHASE_ANALYZE_ENR => 'Analyzing ENR duplicates',
            AllocationProjectionDeduplicator::PHASE_ANALYZE_FINGERPRINT => 'Analyzing fingerprint duplicates',
            AllocationProjectionDeduplicator::PHASE_ANALYZE_ORPHANS => 'Analyzing orphan projection rows',
            AllocationProjectionDeduplicator::PHASE_DELETE_BATCH => 'Deleting duplicates',
            AllocationProjectionDeduplicator::PHASE_DELETE_ORPHANS => 'Removing orphan projection rows',
            default => $phase,
        };

        $this->io->section($title);
    }

    private function finishProgressBar(): void
    {
        if (!$this->progressBar instanceof ProgressBar) {
            return;
        }

        $this->progressBar->finish();
        $this->output->writeln('');
        $this->progressBar = null;
    }
}
