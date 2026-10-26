<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use Symfony\Component\HttpFoundation\Response;

final readonly class HealthCheckReport
{
    /**
     * @param array<string, string>            $checks
     * @param list<string>                     $publicCheckKeys contributed checks shown on GET /health besides the database
     * @param array<string, HealthCheckStatus> $statuses        status of each check, for ops surfaces
     */
    public function __construct(
        public HealthCheckStatus $status,
        public string $version,
        public array $checks,
        private array $publicCheckKeys = [],
        public array $statuses = [],
    ) {
    }

    public function statusOf(string $key): HealthCheckStatus
    {
        return $this->statuses[$key] ?? HealthCheckStatus::Healthy;
    }

    public function httpStatusCode(): int
    {
        return HealthCheckStatus::Unhealthy === $this->status
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK;
    }

    /**
     * Full report for authenticated ops surfaces (e.g. admin dashboard).
     *
     * @return array{status: string, version: string, checks: array<string, string>}
     *
     * @psalm-suppress PossiblyUnusedMethod Used by unit tests; intended for authenticated ops payloads
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'version' => $this->version,
            'checks' => $this->checks,
        ];
    }

    /**
     * Slim public payload for GET /health (no version, no messenger details). Public contributed checks
     * (e.g. storage) add only their status string.
     *
     * @return array{status: string, checks: array<string, string>}
     */
    public function toPublicArray(): array
    {
        $checks = [];
        if (isset($this->checks['database'])) {
            $checks['database'] = $this->checks['database'];
        }

        foreach ($this->publicCheckKeys as $key) {
            if (isset($this->checks[$key])) {
                $checks[$key] = $this->checks[$key];
            }
        }

        return [
            'status' => $this->status->value,
            'checks' => $checks,
        ];
    }
}
