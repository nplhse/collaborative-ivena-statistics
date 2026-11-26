<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Cleanup;

use App\Encounter\Application\Contract\EncounterCleanupInterface;
use App\Encounter\Application\Message\CleanUpEncountersMessage;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the cleanup of encounters without clinical records (#1300) and records its counts on the job run.
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class CleanUpEncountersMessageHandler
{
    public function __construct(
        private EncounterCleanupInterface $cleanup,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    public function __invoke(CleanUpEncountersMessage $message): void
    {
        $this->jobRun->addMetrics($this->cleanup->run($message->hospitalId)->metrics());
    }
}
