<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Http;

final class BoundedContextResolver
{
    public function resolveFromController(?string $controller): ?string
    {
        if (!\is_string($controller) || '' === $controller) {
            return null;
        }

        $class = $controller;
        if (str_contains($controller, '::')) {
            [$class] = explode('::', $controller, 2);
        }

        if (!str_starts_with($class, 'App\\')) {
            return null;
        }

        $parts = explode('\\', $class);
        if (\count($parts) < 2) {
            return null;
        }

        return $parts[1];
    }
}
