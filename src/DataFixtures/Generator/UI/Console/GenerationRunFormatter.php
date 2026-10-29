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

    /**
     * The first line of an error, for overviews; database errors carry the statement and the server's detail on
     * further lines. The whole text with $full (console -v).
     */
    public static function error(?string $error, bool $full = false): string
    {
        $error = trim((string) $error);
        if ('' === $error) {
            return '–';
        }
        if ($full) {
            return $error;
        }
        $first = strtok($error, "\n");

        return false === $first || $first === $error ? $error : trim($first).' … (full text with -v)';
    }
}
