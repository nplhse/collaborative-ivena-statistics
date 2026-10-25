<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

/**
 * Whether an event's volume results can still change (ADR 015). Events without volume rows
 * have neither state and only appear when no state is filtered.
 */
enum ClosureResultState: string
{
    case Final = 'final';
    case Provisional = 'provisional';
}
