<?php

declare(strict_types=1);

namespace App\Engagement\Application\Dto;

enum MonthlyReminderTrigger: string
{
    case Scheduler = 'scheduler';
    case Admin = 'admin';
    case Cli = 'cli';
}
