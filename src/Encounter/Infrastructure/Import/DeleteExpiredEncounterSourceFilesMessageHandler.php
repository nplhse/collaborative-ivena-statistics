<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Encounter\Application\Import\EncounterSourceFileRetention;
use App\Encounter\Application\Message\DeleteExpiredEncounterSourceFilesMessage;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily retention job for encounter upload files (#1049); the counters go to its job run (ADR 019).
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class DeleteExpiredEncounterSourceFilesMessageHandler
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EncounterSourceFileRetention $retention,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(DeleteExpiredEncounterSourceFilesMessage $message): void
    {
        $result = $this->retention->run();

        $this->jobRun->addMetrics($result->metrics() + ['retention_days' => $this->retention->retentionDays()]);
    }
}
