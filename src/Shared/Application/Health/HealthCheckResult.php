<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

/**
 * Outcome of one contributed health check. The value is a short status string; only checks marked public
 * appear in the public `/health` payload, so the value must never contain sizes, paths or other details.
 */
final readonly class HealthCheckResult
{
    public function __construct(
        public string $key,
        public HealthCheckStatus $status,
        public string $value,
        public bool $public = false,
    ) {
    }
}
