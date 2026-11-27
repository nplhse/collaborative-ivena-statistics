<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\Application\Health\HealthCheckService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthCheckController
{
    public function __construct(
        private HealthCheckService $healthCheckService,
    ) {
    }

    /**
     * Stateless (#1685): monitoring sends no cookies, and the cookie consent and analytics listeners skip stateless
     * routes, so nothing before the controller reads the database. With the database down it still answers its 503.
     */
    #[Route('/health', name: 'app_health', methods: [Request::METHOD_GET], stateless: true)]
    public function __invoke(): JsonResponse
    {
        $report = $this->healthCheckService->check();

        return new JsonResponse(
            $report->toPublicArray(),
            $report->httpStatusCode(),
        );
    }
}
