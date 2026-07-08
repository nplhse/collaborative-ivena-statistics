<?php

declare(strict_types=1);

namespace App\Engagement\Domain\Enum;

enum MonthlyReminderDispatchStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
