<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\DTO\FailedImportRowsAction;
use App\Import\Application\DTO\FailedImportRowsCandidate;
use App\Import\Application\Service\FailedImportRowsDiscarder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:discard-failed-rows',
    description: 'Report rows left by failed imports and, with --force, remove them. Does not reprocess imports.',
)]
final readonly class DiscardFailedImportRowsCommand
{
    public function __construct(private FailedImportRowsDiscarder $discarder)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Remove the rows; without it nothing is changed', name: 'force')]
        bool $force = false,
    ): int {
        $io->title('Rows of failed imports');
        if (!$force) {
            $io->note('Dry run: nothing is changed. Use --force to remove the rows marked "discard".');
        }

        $candidates = $this->discarder->candidates();
        if ([] === $candidates) {
            $io->success('No failed import holds rows or replaced rows of other imports.');

            return Command::SUCCESS;
        }

        $this->renderCandidates($io, $candidates);
        $this->renderHospitalsLeaving($io, $candidates);

        if ($force) {
            $discarded = $this->discarder->discard($candidates);
            $io->writeln(\sprintf('Removed the rows of %d import(s).', \count($discarded)));
        }

        $this->renderReprocessing($io, $candidates);

        $io->success($force ? 'Finished.' : 'Dry run finished.');

        return Command::SUCCESS;
    }

    /**
     * @param list<FailedImportRowsCandidate> $candidates
     */
    private function renderCandidates(SymfonyStyle $io, array $candidates): void
    {
        $io->table(
            ['Import', 'Hospital', 'Period', 'Allocations', 'Closures', 'Projection', 'Replaced', 'Action'],
            array_map(static fn (FailedImportRowsCandidate $candidate): array => [
                (string) $candidate->importId,
                $candidate->hospitalName,
                \sprintf('%s – %s', substr($candidate->periodStart ?? '?', 0, 10), substr($candidate->periodEnd ?? '?', 0, 10)),
                (string) $candidate->allocations,
                (string) $candidate->closureIntervals,
                (string) $candidate->projectionRows,
                (string) $candidate->replacedRows,
                $candidate->action()->value,
            ], $candidates),
        );
    }

    /**
     * @param list<FailedImportRowsCandidate> $candidates
     */
    private function renderHospitalsLeaving(SymfonyStyle $io, array $candidates): void
    {
        $hospitals = [];
        foreach ($candidates as $candidate) {
            if ($candidate->hospitalLeavesStatistics && FailedImportRowsAction::Discard === $candidate->action() && $candidate->allocations > 0) {
                $hospitals[$candidate->hospitalName] = true;
            }
        }

        if ([] !== $hospitals) {
            $io->warning('These hospitals have no other allocations and leave the comparison groups: '.implode(', ', array_keys($hospitals)));
        }
    }

    /**
     * @param list<FailedImportRowsCandidate> $candidates
     */
    private function renderReprocessing(SymfonyStyle $io, array $candidates): void
    {
        $importIds = [];
        foreach ($candidates as $candidate) {
            array_push($importIds, ...$candidate->importsToReprocess);
        }

        $importIds = array_values(array_unique($importIds));
        sort($importIds);
        if ([] === $importIds) {
            return;
        }

        $io->section('Reprocess after discarding');
        $io->writeln('A failed import replaced rows of older imports. After the rows are removed, reprocess these imports:');
        $io->listing(array_map(static fn (int $id): string => 'php bin/console app:import:start '.$id, $importIds));
    }
}
