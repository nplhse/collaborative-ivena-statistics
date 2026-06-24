<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\DTO\ImportRequeueBatchOptions;
use App\Import\Application\DTO\ImportRequeueBatchSummary;
use App\Import\Application\ImportDispatchExitCode;
use App\Import\Application\Service\ImportRequeueBatchOrchestrator;
use App\Import\Application\Service\ImportRequeueRunControl;
use App\Import\UI\Console\Input\ImportRequeueInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:requeue-all',
    description: 'Re-queue all allocation imports via Messenger sequentially. Exit codes: 0=all dispatched, 1=some dispatch failures, 2=critical (signal, max retries, invalid options). Requires ext-pcntl for graceful SIGINT/SIGTERM handling.',
)]
final class RequeueAllImportsCommand implements SignalableCommandInterface
{
    private ?ImportRequeueRunControl $runControl = null;

    public function __construct(
        private readonly ImportRequeueBatchOrchestrator $orchestrator,
    ) {
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        if (!\function_exists('pcntl_signal')) {
            return [];
        }

        return [\SIGINT, \SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->runControl?->requestStop($signal);

        return false;
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] ImportRequeueInput $input,
    ): int {
        $options = new ImportRequeueBatchOptions(
            dryRun: $input->dryRun,
            fromId: $input->fromId,
            limit: $input->limit,
            onlyId: $input->onlyId,
            resume: $input->resume,
            runId: $input->runId,
            maxRetriesPerImport: $input->maxRetriesPerImport,
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
