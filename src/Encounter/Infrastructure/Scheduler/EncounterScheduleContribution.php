<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Scheduler;

use App\Encounter\Application\Message\DeleteExpiredEncounterSourceFilesMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class EncounterScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string JOB_SOURCE_FILE_RETENTION = 'encounter_source_file_retention';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron('10 3 * * *', new DeleteExpiredEncounterSourceFilesMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            DeleteExpiredEncounterSourceFilesMessage::class => self::JOB_SOURCE_FILE_RETENTION,
        ];
    }
}
