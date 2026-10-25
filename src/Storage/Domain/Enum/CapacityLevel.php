<?php

declare(strict_types=1);

namespace App\Storage\Domain\Enum;

/**
 * How full the storage is relative to its capacity reference (ADR 017, "Capacity levels").
 * Unknown means there is no reference or no usable measurement; it has no rank and never triggers alerts.
 */
enum CapacityLevel: string
{
    case Normal = 'normal';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';
    case Exceeded = 'exceeded';
    case Unknown = 'unknown';

    /**
     * Higher is fuller; null for unknown.
     */
    public function rank(): ?int
    {
        return match ($this) {
            self::Normal => 0,
            self::Notice => 1,
            self::Warning => 2,
            self::Critical => 3,
            self::Exceeded => 4,
            self::Unknown => null,
        };
    }

    public function isKnown(): bool
    {
        return self::Unknown !== $this;
    }

    public function labelKey(): string
    {
        return 'storage.level.'.$this->value;
    }
}
