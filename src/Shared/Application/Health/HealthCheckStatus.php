<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

enum HealthCheckStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
}
