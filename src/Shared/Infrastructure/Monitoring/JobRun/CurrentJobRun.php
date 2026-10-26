<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun;

use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects what a handler reports about the run JobRunMiddleware is recording. A worker handles one message
 * at a time, so one active run per process is enough.
 */
final class CurrentJobRun implements CurrentJobRunInterface, ResetInterface
{
    private bool $active = false;

    private ?string $skipReason = null;

    /** @var array<string, int|bool|string|null> */
    private array $metrics = [];

    public function begin(): void
    {
        $this->reset();
        $this->active = true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    #[\Override]
    public function addMetrics(array $metrics): void
    {
        if (!$this->active) {
            return;
        }

        $this->metrics = array_merge($this->metrics, $metrics);
    }

    #[\Override]
    public function markSkipped(string $reason): void
    {
        if (!$this->active) {
            return;
        }

        $this->skipReason = $reason;
    }

    public function skipReason(): ?string
    {
        return $this->skipReason;
    }

    /**
     * @return array<string, int|bool|string|null>
     */
    public function metrics(): array
    {
        return null === $this->skipReason ? $this->metrics : ['skip_reason' => $this->skipReason] + $this->metrics;
    }

    #[\Override]
    public function reset(): void
    {
        $this->active = false;
        $this->skipReason = null;
        $this->metrics = [];
    }
}
