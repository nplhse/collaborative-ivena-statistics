<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Scheduler;

use App\Content\Application\Message\CheckMediaIntegrityMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019). The media integrity check runs after the
 * nightly backup and the storage measurement, so it rarely meets their lock.
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class ContentScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string JOB_MEDIA_INTEGRITY = 'media_integrity_check';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron('10 5 * * *', new CheckMediaIntegrityMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            CheckMediaIntegrityMessage::class => self::JOB_MEDIA_INTEGRITY,
        ];
    }
}
