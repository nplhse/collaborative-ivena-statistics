<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Scheduler;

use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use App\Storage\Application\Message\MeasureStorageMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class StorageScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string JOB = 'storage_measurement';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron('30 3 * * *', new MeasureStorageMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            MeasureStorageMessage::class => self::JOB,
        ];
    }
}
