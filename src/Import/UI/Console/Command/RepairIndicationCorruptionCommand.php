<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\DTO\ImportRequeueBatchSummary;
use App\Import\Application\Repair\IndicationCorruptionRepairService;
use App\Import\Application\Repair\QuoteBrokenImportCandidate;
use App\Import\UI\Console\Input\RepairIndicationCorruptionInput;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:repair-indication-corruption',
    description: 'Rehash/merge corrupted indication raws, then requeue quote-broken imports whose source CSV is still on disk. Not for routine use.',
)]
final readonly class RepairIndicationCorruptionCommand
{
    public function __construct(
        private IndicationCorruptionRepairService $repairService,
        private LoggerInterface $importLogger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] RepairIndicationCorruptionInput $input,
    ): int {
        $since = $this->parseSince($input->since);
        if (!$since instanceof \DateTimeImmutable) {
            $io->error(sprintf('Invalid --since date "%s". Use YYYY-MM-DD.', $input->since));

            return Command::INVALID;
        }

        $io->title('Repair CSV indication corruption');
        if ($input->dryRun) {
            $io->note('Dry run: no rows will be written and no imports will be dispatched.');
        }

        $result = $this->repairService->run(
            dryRun: $input->dryRun,
            skipMerge: $input->skipMerge,
            skipRequeue: $input->skipRequeue,
            skipProjection: $input->skipProjection,
            since: $since,
            onlyImportId: $input->onlyImportId,
        );

        $io->section('IndicationRaw merge');
        $io->listing([
            sprintf('Hashes to update: %d', $result->merge->rehashed),
            sprintf('Merge/restore actions: %d', \count($result->merge->actions)),
            sprintf('Projection imports: %d', \count($result->merge->affectedImportIds)),
        ]);

        if ([] !== $result->merge->actions) {
            $rows = [];
            foreach ($result->merge->actions as $action) {
                $rows[] = [
                    $action->type,
                    (string) $action->loserId,
                    null !== $action->survivorId ? (string) $action->survivorId : '-',
                    (string) $action->code,
                    $action->beforeName,
                    $action->afterName,
                    (string) $action->allocationCount,
                ];
            }
            $io->table(
                ['Type', 'From raw', 'To raw', 'Code', 'Before', 'After', 'Allocations'],
                $rows,
            );
        }

        $io->section('Quote-broken imports (source file gate)');
        $this->renderCandidates($io, 'requeue-ready', $result->discovery->ready);
        $this->renderCandidates($io, 'skipped (missing source)', $result->discovery->skipped);

        foreach ($result->discovery->skipped as $skipped) {
            $io->warning(sprintf(
                'Import #%d (%s) skipped: source file %s (%s).',
                $skipped->importId,
                $skipped->hospitalName ?? 'unknown hospital',
                $skipped->filePath ?? '(empty path)',
                $skipped->sourceStatus->value,
            ));
        }

        if ($result->requeue instanceof ImportRequeueBatchSummary) {
            $io->section('Requeue');
            $io->listing([
                $input->dryRun
                    ? sprintf('Would dispatch: %d', $result->requeue->wouldDispatch)
                    : sprintf('Dispatched: %d', $result->requeue->dispatched),
                sprintf('Requeue failed: %d', $result->requeue->failed),
            ]);
        }

        if (!$input->dryRun && !$input->skipProjection) {
            $io->writeln(sprintf('Projection rebuilt for %d merge-affected import(s).', $result->projectionsRebuilt));
        }

        if ([] !== $result->discovery->skipped) {
            $this->importLogger->warning('import.repair.missing_source_files', [
                'count' => \count($result->discovery->skipped),
                'import_ids' => array_map(
                    static fn (QuoteBrokenImportCandidate $c): int => $c->importId,
                    $result->discovery->skipped,
                ),
            ]);
            $io->warning(sprintf(
                '%d import(s) were not requeued because the source CSV is missing or unreadable. Restore files from backup and re-run with --skip-merge --only-import-id=…',
                \count($result->discovery->skipped),
            ));
        }

        if ($input->dryRun) {
            $io->success('Dry run finished. Re-run without --dry-run to apply changes.');

            return Command::SUCCESS;
        }

        $io->success('Repair finished.');

        return Command::SUCCESS;
    }

    /**
     * @param list<QuoteBrokenImportCandidate> $candidates
     */
    private function renderCandidates(SymfonyStyle $io, string $title, array $candidates): void
    {
        $io->writeln(sprintf('<info>%s</info> (%d)', $title, \count($candidates)));
        if ([] === $candidates) {
            return;
        }

        $rows = [];
        foreach ($candidates as $candidate) {
            $rows[] = [
                (string) $candidate->importId,
                $candidate->hospitalName ?? '-',
                $candidate->importName ?? '-',
                $candidate->filePath ?? '-',
                (string) $candidate->rejectCount,
                $candidate->sourceStatus->value,
            ];
        }
        $io->table(['Import ID', 'Hospital', 'Name', 'File', 'Rejects', 'Source'], $rows);
    }

    private function parseSince(string $since): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $since);
        if (!$date instanceof \DateTimeImmutable) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (\is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) {
            return null;
        }

        return $date;
    }
}
