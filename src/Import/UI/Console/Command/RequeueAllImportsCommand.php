<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\DTO\ImportRequeueBatchOptions;
use App\Import\Application\DTO\ImportRequeueBatchSummary;
use App\Import\Application\ImportDispatchExitCode;
use App\Import\Application\Service\ImportRequeueBatchOrchestrator;
use App\Import\Application\Service\ImportRequeueRunControl;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:requeue-all',
    description: 'Re-queue all allocation imports via Messenger sequentially. Exit codes: 0=all dispatched, 1=some dispatch failures, 2=critical (signal, max retries, invalid options). Requires ext-pcntl for graceful SIGINT/SIGTERM handling.',
)]
final class RequeueAllImportsCommand extends Command implements SignalableCommandInterface
{
    private ?ImportRequeueRunControl $runControl = null;

    public function __construct(
        private readonly ImportRequeueBatchOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show which imports would be dispatched without persisting or dispatching')
            ->addOption('from-id', null, InputOption::VALUE_REQUIRED, 'Start from this import ID', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of imports to process')
            ->addOption('only-id', null, InputOption::VALUE_REQUIRED, 'Process only this import ID')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Resume the latest incomplete batch run')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Resume a specific batch run by ID')
            ->addOption('max-retries-per-import', null, InputOption::VALUE_REQUIRED, 'Max dispatch attempts per import before critical exit', '3');
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

        $fromId = max(1, (int) $input->getOption('from-id'));
        $limitOption = $input->getOption('limit');
        $limit = null !== $limitOption && '' !== $limitOption ? max(1, (int) $limitOption) : null;
        $onlyIdOption = $input->getOption('only-id');
        $onlyId = null !== $onlyIdOption && '' !== $onlyIdOption ? (int) $onlyIdOption : null;
        $runIdOption = $input->getOption('run-id');
        $runId = null !== $runIdOption && '' !== $runIdOption ? (int) $runIdOption : null;
        $maxRetries = max(1, (int) $input->getOption('max-retries-per-import'));

        $options = new ImportRequeueBatchOptions(
            dryRun: (bool) $input->getOption('dry-run'),
            fromId: $fromId,
            limit: $limit,
            onlyId: $onlyId,
            resume: (bool) $input->getOption('resume'),
            runId: $runId,
            maxRetriesPerImport: $maxRetries,
        );

        $this->runControl = new ImportRequeueRunControl();

        $summary = $this->orchestrator->run($options, $this->runControl);

        $this->runControl = null;

        $this->renderResults($io, $summary, $options->dryRun);
        $this->renderSummary($io, $summary, $options->dryRun);

        return $summary->exitCode;
    }

    private function renderResults(SymfonyStyle $io, ImportRequeueBatchSummary $summary, bool $dryRun): void
    {
        if ([] === $summary->results) {
            $io->warning('No imports matched the given filters.');

            return;
        }

        $rows = [];
        foreach ($summary->results as $result) {
            $rows[] = [
                (string) $result->importId,
                $result->name ?? '-',
                $result->filePath ?? '-',
                $result->consoleStatus,
            ];
        }

        $io->table(['Import ID', 'Name', 'File', 'Status'], $rows);

        if ($dryRun) {
            $io->note('Dry-run: no checkpoints written and no messages dispatched.');
        }
    }

    private function renderSummary(SymfonyStyle $io, ImportRequeueBatchSummary $summary, bool $dryRun): void
    {
        if ($dryRun) {
            $io->success(sprintf('Dry-run complete. Would dispatch: %d', $summary->wouldDispatch));

            return;
        }

        $io->section('Summary');
        $io->listing([
            sprintf('Run ID: %s', null !== $summary->runId ? (string) $summary->runId : 'n/a'),
            sprintf('Dispatched: %d', $summary->dispatched),
            sprintf('Failed: %d', $summary->failed),
            sprintf('Skipped: %d', $summary->skipped),
        ]);

        if ($summary->interrupted) {
            $io->warning('Batch interrupted. Re-run with --resume to continue.');

            return;
        }

        if ($summary->maxRetriesExceeded) {
            $io->error('Max retries exceeded for at least one import. Fix the issue before retrying.');

            return;
        }

        if (ImportDispatchExitCode::SUCCESS === $summary->exitCode) {
            $io->success('All imports dispatched successfully.');
        } else {
            $io->warning('Batch finished with dispatch failures.');
        }
    }
}
