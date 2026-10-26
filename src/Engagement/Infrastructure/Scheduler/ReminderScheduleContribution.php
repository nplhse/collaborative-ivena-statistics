<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Scheduler;

use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class ReminderScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string JOB = 'monthly_reminder';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron(
                '0 8 * * *',
                new SendMonthlySubmissionRemindersMessage(),
                self::TIMEZONE,
            ),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            SendMonthlySubmissionRemindersMessage::class => self::JOB,
        ];
    }
}
