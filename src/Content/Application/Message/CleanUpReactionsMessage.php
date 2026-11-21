<?php

declare(strict_types=1);

namespace App\Content\Application\Message;

/**
 * Triggers the daily removal of reactions whose target was deleted (#467).
 */
final readonly class CleanUpReactionsMessage
{
}
