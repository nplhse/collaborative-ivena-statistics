<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * The five independent counters of a population-hour (ADR 015).
 * `total` is the sum of the three urgency metrics and is never stored; resource
 * metrics count allocations of any urgency and are never added to the total.
 */
enum ClosureVolumeMetricKind: string
{
    case Emergency = 'emergency';
    case Inpatient = 'inpatient';
    case Outpatient = 'outpatient';
    case Resus = 'resus';
    case Cathlab = 'cathlab';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Emergency, self::Inpatient, self::Outpatient, self::Resus, self::Cathlab];
    }

    /** @return list<self> */
    public static function urgencyMetrics(): array
    {
        return [self::Emergency, self::Inpatient, self::Outpatient];
    }

    public static function forUrgency(int $urgency): self
    {
        return match ($urgency) {
            1 => self::Emergency,
            2 => self::Inpatient,
            3 => self::Outpatient,
            default => throw new \InvalidArgumentException(sprintf('Urgency %d has no metric.', $urgency)),
        };
    }

    public function urgencyCode(): ?int
    {
        return match ($this) {
            self::Emergency => 1,
            self::Inpatient => 2,
            self::Outpatient => 3,
            self::Resus, self::Cathlab => null,
        };
    }

    public function isResource(): bool
    {
        return null === $this->urgencyCode();
    }

    /**
     * Urgencies that must all be closed at the same time for a closure to bind this metric.
     *
     * @return list<int>
     */
    public function bindingUrgencies(): array
    {
        $urgency = $this->urgencyCode();

        return null === $urgency ? [1, 2, 3] : [$urgency];
    }
}
