<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

use App\DataFixtures\Generator\Application\Run\Message\FinishGenerationRun;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Starts the final stage once no hospital of a run is open. Called after every hospital that completes or
 * fails; claimFinishing() lets exactly one caller dispatch FinishGenerationRun.
 */
final readonly class GenerationRunCompletion
{
    public function __construct(
        private GenerationRunStore $store,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function check(string $runId): void
    {
        if ($this->store->claimFinishing($runId, $this->clock->now())) {
            $this->messageBus->dispatch(new FinishGenerationRun($runId));
        }
    }
}
