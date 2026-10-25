<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

enum ClosureVolumeStratum: string
{
    case All = 'all';
    case Sk1 = 'sk1';
    case Sk2 = 'sk2';
    case Sk3 = 'sk3';
    case Resus = 'resus';
    case Cathlab = 'cathlab';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::All;
    }

    public function storageStratum(): string
    {
        return match ($this) {
            self::Resus => 'resus',
            self::Cathlab => 'cathlab',
            default => 'base',
        };
    }

    public function urgencyCode(): ?int
    {
        return match ($this) {
            self::Sk1 => 1,
            self::Sk2 => 2,
            self::Sk3 => 3,
            default => null,
        };
    }

    /**
     * Stored metrics this view sums. `all` is the total of the three urgency metrics.
     *
     * @return list<ClosureVolumeMetricKind>
     */
    public function metrics(): array
    {
        return match ($this) {
            self::All => ClosureVolumeMetricKind::urgencyMetrics(),
            self::Sk1 => [ClosureVolumeMetricKind::Emergency],
            self::Sk2 => [ClosureVolumeMetricKind::Inpatient],
            self::Sk3 => [ClosureVolumeMetricKind::Outpatient],
            self::Resus => [ClosureVolumeMetricKind::Resus],
            self::Cathlab => [ClosureVolumeMetricKind::Cathlab],
        };
    }

    /**
     * @return list<string>
     */
    public function metricValues(): array
    {
        return array_map(static fn (ClosureVolumeMetricKind $metric): string => $metric->value, $this->metrics());
    }

    /** @return list<self> */
    public static function choices(): array
    {
        return [self::All, self::Sk1, self::Sk2, self::Sk3, self::Resus, self::Cathlab];
    }
}
