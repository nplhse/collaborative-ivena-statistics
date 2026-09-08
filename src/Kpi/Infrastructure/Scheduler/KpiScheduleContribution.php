<?php

declare(strict_types=1);

namespace App\Kpi\Infrastructure\Scheduler;

use App\Kpi\Application\Message\GenerateDailyKpisMessage;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/** @psalm-suppress UnusedClass Tagged via ScheduleContributionInterface. */
final readonly class KpiScheduleContribution implements ScheduleContributionInterface
{
    #[\Override]
    public function recurringMessages(): array
    {
        return [
            RecurringMessage::cron(
                '0 */6 * * *',
                new GenerateDailyKpisMessage(),
            ),
        ];
    }
}
