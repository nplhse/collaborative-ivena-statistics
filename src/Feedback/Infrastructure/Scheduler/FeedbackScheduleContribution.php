<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Scheduler;

use App\Feedback\Application\Message\SendFeedbackQuarantineSummaryMessage;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Schedule and job_run names of this context's scheduler jobs (ADR 019).
 *
 * @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface and RecordedJobsInterface.
 */
final readonly class FeedbackScheduleContribution implements ScheduleContributionInterface, RecordedJobsInterface
{
    public const string SUMMARY_JOB = 'feedback_quarantine_summary';

    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            // Mondays 08:00.
            RecurringMessage::cron('0 8 * * 1', new SendFeedbackQuarantineSummaryMessage(), self::TIMEZONE),
        ];
    }

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            SendFeedbackQuarantineSummaryMessage::class => self::SUMMARY_JOB,
        ];
    }
}
