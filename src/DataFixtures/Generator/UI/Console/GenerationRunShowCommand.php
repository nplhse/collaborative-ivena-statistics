<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Run\GenerationRunStatus;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate:run:show',
    description: 'Show one synthetic data generation run: settings, hospitals, imports, failures and its background jobs (ADR 023).',
)]
final readonly class GenerationRunShowCommand
{
    public function __construct(
        private GenerationRunStore $store,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Run id; the newest run when omitted')]
        ?string $run = null,
    ): int {
        $id = $run ?? ($this->store->recentIds(1)[0] ?? null);
        $view = null === $id ? null : $this->store->find($id);
        if (!$view instanceof \App\DataFixtures\Generator\Application\Run\GenerationRunView) {
            $io->error(null === $id ? 'No generation runs yet.' : \sprintf('Unknown generation run "%s".', $id));

            return Command::FAILURE;
        }

        $now = $this->clock->now();
        $io->title('Generation run '.$view->id);
        $io->definitionList(
            ['Status' => GenerationRunFormatter::status($view, $now)],
            ['Stage' => $view->stage->value],
            ['Scope' => $view->scope->value],
            ['Trigger' => $view->trigger->value],
            ['Seed' => (string) $view->seed],
            ['Period' => \sprintf('%s – %s', $view->fromMonth ?? '?', $view->toMonth ?? '?')],
            ['Notify participants' => $view->notify ? 'yes' : 'no'],
            ['Initiated by user' => null === $view->initiatedById ? '–' : (string) $view->initiatedById],
            ['Settings' => [] === $view->settings ? '–' : json_encode($view->settings, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)],
            ['Created' => GenerationRunFormatter::time($view->createdAt, $now)],
            ['Generated (sync stage done)' => GenerationRunFormatter::time($view->generatedAt, $now)],
            ['Finished' => GenerationRunFormatter::time($view->finishedAt, $now)],
            ['Totals' => \sprintf('%d months, %d allocations, %d closures', $view->totalMonths(), $view->totalAllocations(), $view->totalClosures())],
        );

        $rows = [];
        foreach ($view->hospitals as $hospital) {
            $rows[] = [
                \sprintf('%s (%d)', $hospital->hospitalName, $hospital->hospitalId),
                $hospital->status->value.(null === $hospital->reasonCode ? '' : ' ('.$hospital->reasonCode.')'),
                (string) $hospital->months,
                (string) $hospital->allocations,
                (string) $hospital->closures,
                (string) $hospital->mciCases,
                implode(', ', $hospital->allocationImportIds) ?: '–',
                implode(', ', $hospital->closureImportIds) ?: '–',
                (string) $hospital->projectionAttempts,
                \sprintf('%s / %s / %s', GenerationRunFormatter::time($hospital->startedAt, $now), GenerationRunFormatter::time($hospital->generatedAt, $now), GenerationRunFormatter::time($hospital->finishedAt, $now)),
                $hospital->error ?? '',
            ];
        }
        $io->section('Hospitals');
        $io->table(['Hospital', 'Status', 'Months', 'Allocations', 'Closures', 'MCI cases', 'Allocation imports', 'Closure imports', 'Projection attempts', 'Started / generated / finished', 'Error'], $rows);

        $jobs = $this->store->jobRuns($view->id);
        $io->section('Background jobs (job_run)');
        if ([] === $jobs) {
            $io->text('None recorded. Jobs are recorded only when a Messenger worker handles them.');
        } else {
            $io->table(['Job', 'Hospital', 'Attempt', 'Status', 'Started', 'Duration (ms)', 'Error'], array_map(static fn (array $job): array => [
                $job['job'],
                (string) ($job['hospital_id'] ?? '–'),
                (string) $job['attempt'],
                $job['status'],
                $job['started_at'],
                (string) ($job['duration_ms'] ?? '–'),
                $job['error_class'] ?? '',
            ], $jobs));
        }

        if ($view->isStale($now) || GenerationRunStatus::Failed === $view->status) {
            $io->note(\sprintf('Retry failed or stuck hospitals with: php bin/console app:generate:run:retry %s', $view->id));
        }

        return Command::SUCCESS;
    }
}
