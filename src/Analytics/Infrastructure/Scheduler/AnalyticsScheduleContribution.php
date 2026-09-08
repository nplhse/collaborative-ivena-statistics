<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Scheduler;

use App\Analytics\Application\Message\AggregateDailyAnalyticsMessage;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/** @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface. */
final readonly class AnalyticsScheduleContribution implements ScheduleContributionInterface
{
    private const string TIMEZONE = 'Europe/Berlin';

    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron(
                '15 2 * * *',
                new AggregateDailyAnalyticsMessage(),
                self::TIMEZONE,
            ),
        ];
    }
}
