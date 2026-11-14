<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PlausibilityLearner;
use App\Encounter\Application\Plausibility\PlausibilityLearningReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Learns a draft snapshot of the global plausibility table from the eligible pairs of all hospitals (ADR 033, #1155).
 * The active snapshot stays as it is; a person activates the draft on the tool page after reading its report.
 *
 * For operators: prints the period, the number of eligible pairs and the replay over all hospitals and per hospital
 * id (aggregates, never a pair or a case). The tool page shows reviewers the replay of their reviewed hospitals only.
 */
#[AsCommand(
    name: 'app:encounter:learn-plausibility',
    description: 'Learn a draft snapshot of the plausibility table from confirmed pairs and links the bonus did not decide (ADR 033).',
)]
final readonly class LearnPlausibilityCommand
{
    public function __construct(
        private PlausibilityLearner $learner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'First IVENA arrival day of the eligible pairs (YYYY-MM-DD); default: all')]
        string $from = '',
        #[Option(description: 'Last IVENA arrival day of the eligible pairs (YYYY-MM-DD); default: all')]
        string $to = '',
    ): int {
        $first = '' === $from ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $last = '' === $to ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if (false === $first || false === $last || ($first instanceof \DateTimeImmutable && $last instanceof \DateTimeImmutable && $last < $first)) {
            $io->error('Pass --from and --to as YYYY-MM-DD, --to not before --from.');

            return Command::INVALID;
        }

        try {
            $snapshot = $this->learner->learn($first, $last);
        } catch (PlausibilityException $exception) {
            $io->warning(PlausibilityException::NO_ELIGIBLE_PAIRS === $exception->reason ? 'No eligible pairs: nothing to learn from.' : $exception->getMessage());

            return Command::FAILURE;
        }

        $report = PlausibilityLearningReport::fromArray($snapshot->getReport());
        $activeId = $report?->activeSnapshotId;
        $io->success(\sprintf('Learned draft snapshot %d. Review and activate it under /tools/encounter-plausibility.', (int) $snapshot->getId()));
        $io->definitionList(
            ['Period' => \sprintf('%s – %s', $snapshot->getLearnedFrom()?->format('Y-m-d') ?? '–', $snapshot->getLearnedTo()?->format('Y-m-d') ?? '–')],
            ['Eligible pairs' => (string) $snapshot->getEligiblePairs()],
            ['Parameters' => (string) json_encode($snapshot->getLearningParameters())],
            ['Compared with' => null === $activeId ? 'no active snapshot' : \sprintf('active snapshot %d', $activeId)],
        );

        if (!$report instanceof PlausibilityLearningReport) {
            return Command::SUCCESS;
        }

        $total = $report->total();
        $io->section(\sprintf('All hospitals (%d eligible pairs, %d confirmed after a bonus decision)', $total['eligible_pairs'], $total['eligible_from_bonus']));
        $this->variantTable($io, $total['variants']);
        foreach ($report->hospitals as $hospitalId => $figures) {
            $io->section(\sprintf('Hospital %d (%d eligible pairs, %d confirmed after a bonus decision)', $hospitalId, $figures['eligible_pairs'], $figures['eligible_from_bonus']));
            $this->variantTable($io, $figures['variants']);
        }

        $io->note('Nothing changes for matching until the draft is activated and the matcher runs again over the allocations.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array{allocations: int, automatic: int, proposal: int, none: int, decisive: int, agree: int, disagree: int}> $variants
     */
    private function variantTable(SymfonyStyle $io, array $variants): void
    {
        $rows = [];
        foreach (PlausibilityLearningReport::VARIANTS as $variant) {
            $outcome = $variants[$variant] ?? null;
            if (null === $outcome) {
                continue;
            }

            $rows[] = [
                $variant,
                $outcome['allocations'],
                $this->ratio($outcome['automatic'], $outcome['allocations']),
                $outcome['proposal'],
                $outcome['none'],
                $outcome['decisive'],
                $outcome['agree'],
                $outcome['disagree'],
            ];
        }

        $io->table(['Bonus', 'Allocations', 'Automatic', 'Proposal', 'None', 'Decided by the bonus', 'Agree with reference', 'Contradict reference'], $rows);
    }

    private function ratio(int $part, int $total): string
    {
        return 0 === $total ? '–' : \sprintf('%d (%.3f)', $part, $part / $total);
    }
}
