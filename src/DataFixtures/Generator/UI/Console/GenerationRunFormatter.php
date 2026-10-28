<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Run\GenerationRunView;

/**
 * Shared console wording for generation runs.
 */
final class GenerationRunFormatter
{
    public static function status(GenerationRunView $run, \DateTimeImmutable $now): string
    {
        $status = $run->status->value;
        if (null !== $run->reasonCode) {
            $status .= ' ('.$run->reasonCode.')';
        }

        return $run->isStale($now) ? $status.', stale: no change for 30 minutes' : $status;
    }

    public static function time(?\DateTimeImmutable $time, \DateTimeImmutable $now): string
    {
        return $time?->setTimezone($now->getTimezone())->format('Y-m-d H:i:s') ?? '–';
    }
}
