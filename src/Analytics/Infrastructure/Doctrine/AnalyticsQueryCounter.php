<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Doctrine;

/**
 * Request-scoped counter for DB query count and time.
 * Disabled around analytics persistence so the insert itself is excluded.
 */
final class AnalyticsQueryCounter
{
    private int $count = 0;

    private float $timeMs = 0.0;

    private bool $enabled = false;

    public function reset(): void
    {
        $this->count = 0;
        $this->timeMs = 0.0;
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function record(float $durationMs): void
    {
        if (!$this->enabled) {
            return;
        }

        ++$this->count;
        $this->timeMs += $durationMs;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getTimeMs(): int
    {
        return (int) round($this->timeMs);
    }
}
