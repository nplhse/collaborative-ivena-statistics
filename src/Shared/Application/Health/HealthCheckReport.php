<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use Symfony\Component\HttpFoundation\Response;

final readonly class HealthCheckReport
{
    /**
     * @param array<string, string> $checks
     */
    public function __construct(
        public HealthCheckStatus $status,
        public string $version,
        public array $checks,
    ) {
    }

    public function httpStatusCode(): int
    {
        return HealthCheckStatus::Unhealthy === $this->status
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK;
    }

    /**
     * @return array{status: string, version: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'version' => $this->version,
            'checks' => $this->checks,
        ];
    }
}
