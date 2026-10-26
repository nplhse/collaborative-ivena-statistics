<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use App\Shared\Application\Monitoring\Message\CleanUpMonitoringMessage;
use App\Shared\Application\Monitoring\Message\SampleMessengerQueuesMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of the monitoring jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class MonitoringScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string JOB_QUEUE_SAMPLE = 'messenger_queue_sample';
    public const string JOB_RETENTION = 'monitoring_retention';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron('5 * * * *', new SampleMessengerQueuesMessage()),
            RecurringMessage::cron('50 3 * * *', new CleanUpMonitoringMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            SampleMessengerQueuesMessage::class => self::JOB_QUEUE_SAMPLE,
            CleanUpMonitoringMessage::class => self::JOB_RETENTION,
        ];
    }
}
