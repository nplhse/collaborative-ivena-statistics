<?php

declare(strict_types=1);

namespace App\Storage\Application\Event;

use App\Storage\Domain\Enum\CapacityLevel;

/**
 * A measurement was stored and should be announced (ADR 017, "Scheduled measurement and console command").
 * Dispatched synchronously after the row is written; storage alerts and the daily report subscribe to it.
 * Not dispatched for `app:storage:analyze --persist --no-notify`.
 */
final readonly class StorageMeasured
{
    public function __construct(
        public int $measurementId,
        public \DateTimeImmutable $measuredOn,
        public CapacityLevel $level,
    ) {
    }
}
