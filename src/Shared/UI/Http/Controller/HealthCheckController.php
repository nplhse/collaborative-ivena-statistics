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

    #[Route('/health', name: 'app_health', methods: [Request::METHOD_GET])]
    public function __invoke(): JsonResponse
    {
        $report = $this->healthCheckService->check();

        return new JsonResponse(
            $report->toArray(),
            $report->httpStatusCode(),
        );
    }
}
