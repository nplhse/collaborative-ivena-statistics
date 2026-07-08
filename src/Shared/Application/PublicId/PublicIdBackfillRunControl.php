<?php

declare(strict_types=1);

namespace App\Shared\Application\PublicId;

final class PublicIdBackfillRunControl
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
        if (!$this->isStopRequested()) {
            return;
        }

        throw new PublicIdBackfillInterruptedException($this->getSignal() ?? 0);
    }
}
