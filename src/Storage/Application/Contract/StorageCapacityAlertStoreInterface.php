<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Domain\Enum\CapacityLevel;

/**
 * Remembers which capacity level administrators were last told about (ADR 017, "Notifications").
 */
interface StorageCapacityAlertStoreInterface
{
    /**
     * Normal until a level has been recorded.
     */
    public function lastNotifiedLevel(): CapacityLevel;

    public function recordNotified(CapacityLevel $level, \DateTimeImmutable $changedOn, \DateTimeImmutable $now): void;
}
