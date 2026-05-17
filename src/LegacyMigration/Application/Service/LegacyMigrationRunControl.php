<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\LegacyMigration\Application\Exception\LegacyMigrationInterruptedException;

final class LegacyMigrationRunControl
{
    private bool $stopRequested = false;

    private ?int $signal = null;

    public function requestStop(int $signal): void
    {
        $this->stopRequested = true;
        $this->signal = $signal;
    }

    public function isStopRequested(): bool
    {
        return $this->stopRequested;
    }

    public function getSignal(): ?int
    {
        return $this->signal;
    }

    public function throwIfStopRequested(): void
    {
        if (!$this->stopRequested) {
            return;
        }

        throw new LegacyMigrationInterruptedException($this->signal ?? 0, sprintf('Legacy migration interrupted by signal %d.', $this->signal ?? 0));
    }
}
