<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Run\GenerationRunCompletion;
use App\DataFixtures\Generator\Application\Run\GenerationRunStage;
use App\DataFixtures\Generator\Application\Run\Message\FinishGenerationRun;
use App\DataFixtures\Generator\Application\Run\Message\ProjectGeneratedHospital;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Starts the asynchronous stage again for hospitals that failed after generating data or whose message was
 * lost. Generated data is not written again, so a retry never duplicates allocations; hospitals that failed
 * during generation need a new run (for a dataset run: `app:generate:dataset --retry=<run id>`, #877).
 */
#[AsCommand(
    name: 'app:generate:run:retry',
    description: 'Retry the background projections of failed or stuck hospitals of a generation run (ADR 023).',
)]
final readonly class GenerationRunRetryCommand
{
    public function __construct(
        private GenerationRunStore $store,
        private MessageBusInterface $messageBus,
        private GenerationRunCompletion $completion,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Run id')]
        string $run,
    ): int {
        $view = $this->store->find($run);
        if (!$view instanceof \App\DataFixtures\Generator\Application\Run\GenerationRunView) {
            $io->error(\sprintf('Unknown generation run "%s".', $run));

            return Command::FAILURE;
        }
        if (GenerationRunStage::Generating === $view->stage) {
            $io->error('The run is still generating. Retry once its synchronous stage ended.');

            return Command::FAILURE;
        }

        $reopened = $this->store->reopenFailed($run, $this->clock->now());
        $hospitals = array_values(array_unique([...$reopened, ...$this->store->openProjections($run)]));
        sort($hospitals);
        foreach ($hospitals as $hospitalId) {
            $this->messageBus->dispatch(new ProjectGeneratedHospital($run, $hospitalId));
        }

        $after = $this->store->find($run);
        if ([] === $hospitals && GenerationRunStage::Finishing === $after?->stage && !$after->status->isTerminal()) {
            $this->messageBus->dispatch(new FinishGenerationRun($run));
            $io->success('Dispatched the final stage again.');

            return Command::SUCCESS;
        }
        if ([] === $hospitals) {
            $this->completion->check($run);
            $io->info('Nothing to retry: no hospital failed after generating data, none is open.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Dispatched the background stage for %d hospital(s): %s.', \count($hospitals), implode(', ', $hospitals)));

        return Command::SUCCESS;
    }
}
