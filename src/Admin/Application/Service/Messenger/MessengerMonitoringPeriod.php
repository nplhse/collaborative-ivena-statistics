<?php

declare(strict_types=1);

namespace App\Admin\Application\Service\Messenger;

/**
 * Periods of the Messenger page; all of them read hourly rows (kept for 90 days, ADR 019).
 */
enum MessengerMonitoringPeriod: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function hours(): int
    {
        return match ($this) {
            self::Day => 24,
            self::Week => 24 * 7,
            self::Month => 24 * 30,
        };
    }

    public function labelKey(): string
    {
        return 'ops.messenger.period.'.$this->value;
    }

    public static function fromQuery(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Day;
    }
}
