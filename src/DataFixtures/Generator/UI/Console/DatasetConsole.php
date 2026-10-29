<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Dataset\DatasetGenerator;
use App\DataFixtures\Generator\Application\Dataset\DatasetHospitalPlan;
use App\DataFixtures\Generator\Application\Dataset\DatasetPlan;
use App\DataFixtures\Generator\Application\Dataset\DatasetRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console flow of a dataset run, shared by `app:generate:dataset` and `app:generate:dataset --retry`:
 * plan table, refusal or skipping of blocked hospitals, progress, and the summary per hospital.
 */
final readonly class DatasetConsole
{
    public function __construct(private DatasetGenerator $datasets)
    {
    }

    public function run(SymfonyStyle $io, DatasetRequest $request, bool $dryRun, bool $skipBlocked): int
    {
        $plan = $this->datasets->prepare($request);
        $this->renderPlan($io, $plan);

        if ($skipBlocked && [] === $plan->blockers && [] !== $plan->blocked()) {
            $plan = $plan->withoutBlocked();
            $io->note(\sprintf('Skipping %d blocked hospital(s): %s.', \count($plan->skipped), implode(', ', array_map(static fn (DatasetHospitalPlan $h): string => $h->name, $plan->skipped))));
        }
        $blockers = $plan->runBlockers();
        if ([] !== $blockers) {
            $io->error(['Nothing was written. The run is refused:', ...$blockers]);
            if ([] === $plan->blockers && [] !== $plan->blocked() && !$skipBlocked) {
                $io->note('Fix the blocked hospitals, leave them out, or pass --skip-blocked.');
            }

            return Command::FAILURE;
        }
        if ($dryRun) {
            $io->success(\sprintf('Dry run: nothing was written. %d hospital(s), about %d allocations, seed %d.', \count($plan->withWork()), $plan->plannedAllocations(), $plan->seed));

            return Command::SUCCESS;
        }

        $runId = $this->datasets->start($plan);
        $months = array_sum(array_map(static fn (DatasetHospitalPlan $h): int => \count($h->generate), $plan->withWork()));
        $progress = new ConsoleDatasetProgress($io, $months);
        $result = $this->datasets->generate($runId, $progress);
        $progress->finish();

        // One block per hospital: a failed hospital's error is too long for a table cell.
        foreach ($result->run->hospitals ?? [] as $hospital) {
            $io->text(\sprintf('<info>%s</info> (#%d)', $hospital->hospitalName, $hospital->hospitalId));
            $io->definitionList(
                ['Status' => $hospital->status->value.(null === $hospital->reasonCode ? '' : ' ('.$hospital->reasonCode.')')],
                ['Generated' => \sprintf('%d months, %d allocations, %d closures, %d MCI cases', $hospital->months, $hospital->allocations, $hospital->closures, $hospital->mciCases)],
                ['Error' => GenerationRunFormatter::error($hospital->error, $io->isVerbose())],
            );
        }
        foreach ($result->skipped as $name => $reason) {
            $io->text(\sprintf('<info>%s</info>', $name));
            $io->definitionList(['Status' => 'skipped'], ['Reason' => $reason]);
        }
        $io->note(\sprintf('Generation run %s (seed %d): projections of %d hospital(s) continue in the Messenger worker. Follow them with: php bin/console app:generate:run:show %s', $result->runId, $plan->seed, \count($result->handedOver), $result->runId));

        if ($result->hasFailures()) {
            $io->error(\sprintf('Some hospitals failed and were rolled back. Generate them again without duplicates: php bin/console app:generate:dataset --retry=%s', $result->runId));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Generated %d hospital(s).', \count($result->handedOver)));

        return Command::SUCCESS;
    }

    private function renderPlan(SymfonyStyle $io, DatasetPlan $plan): void
    {
        $request = $plan->request;
        $io->definitionList(
            ['Scope' => $request->scope->value],
            ['Period' => \sprintf('%s to %s (data until %s)', $request->period->from, $request->period->to, $plan->lastDay->format('Y-m-d'))],
            ['Existing data' => $request->existing->value],
            ['Seed' => (string) $plan->seed],
            ['Patterns' => $plan->patterns],
        );

        $rows = [];
        $notes = [];
        foreach ($plan->hospitals as $hospital) {
            $rows[] = [
                $hospital->name,
                $hospital->profile,
                $this->months($hospital->generate),
                $this->months($hospital->keep),
                $this->months($hospital->replace),
                (string) \count($hospital->scenarioImports),
                $hospital->isReady() ? (string) $hospital->plannedAllocations : '–',
                $hospital->isReady() ? 'ready' : 'blocked: '.implode('; ', $hospital->blockers),
            ];
            foreach ($hospital->notes as $note) {
                $notes[] = \sprintf('%s: %s', $hospital->name, $note);
            }
        }
        $io->table(['Hospital', 'Profile', 'Generate', 'Keep', 'Replace', 'Scenario imports to delete', 'Planned allocations', 'Status'], $rows);
        if ([] !== $notes) {
            $io->note($notes);
        }
    }

    /**
     * @param list<string> $months
     */
    private function months(array $months): string
    {
        return match (\count($months)) {
            0 => '–',
            1 => $months[0],
            default => \sprintf('%s – %s (%d)', $months[0], $months[\count($months) - 1], \count($months)),
        };
    }
}
