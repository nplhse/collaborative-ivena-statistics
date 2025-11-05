<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Scope;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ScopeRoute
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UrlGeneratorInterface $router,
    ) {
    }

    public function toPath(
        string $scopeType,
        string $scopeId,
        string $gran,
        string $key,
    ): string {
        return $this->router->generate('app_stats_dashboard', [
            'scopeType' => $scopeType,
            'scopeId' => $scopeId,
            'gran' => $gran,
            'key' => $key,
        ]);
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function fromScope(Scope $scope): string
    {
        return $this->toPath(
            $scope->scopeType,
            $scope->scopeId,
            $scope->granularity,
            $scope->periodKey
        );
    }
}
