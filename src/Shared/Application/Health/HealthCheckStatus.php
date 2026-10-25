<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

enum HealthCheckStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';

    public function worst(self $other): self
    {
        return $other->severity() > $this->severity() ? $other : $this;
    }

    private function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Degraded => 1,
            self::Unhealthy => 2,
        };
    }
}
