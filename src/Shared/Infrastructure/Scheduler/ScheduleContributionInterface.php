<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Scheduler\RecurringMessage;

#[AutoconfigureTag('app.schedule_contribution')]
interface ScheduleContributionInterface
{
    /**
     * @return list<RecurringMessage>
     */
    public function recurringMessages(): array;
}
