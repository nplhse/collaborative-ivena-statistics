<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Scheduler;

use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/** @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface. */
final readonly class ReminderScheduleContribution implements ScheduleContributionInterface
{
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
}
