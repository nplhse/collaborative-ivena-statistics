<?php

declare(strict_types=1);

namespace App\User\Application\Message;

/**
 * Triggers the daily folding of failed sign-ins into daily counters and their retention (#1487).
 */
final readonly class AggregateLoginFailuresMessage
{
}
