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
     *
     * @psalm-suppress PossiblyUnusedMethod Not wired yet; the storage alert subscriber uses it.
     */
    public function lastNotifiedLevel(): CapacityLevel;

    /**
     * @psalm-suppress PossiblyUnusedMethod Not wired yet; the storage alert subscriber uses it.
     */
    public function recordNotified(CapacityLevel $level, \DateTimeImmutable $changedOn, \DateTimeImmutable $now): void;
}
