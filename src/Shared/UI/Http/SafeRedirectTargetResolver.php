<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\HttpFoundation\Request;

final class SafeRedirectTargetResolver
{
    public function isSafe(Request $request, string $target): bool
    {
        if ('' === $target) {
            return false;
        }

        if (str_starts_with($target, '/')) {
            return !str_starts_with($target, '//');
        }

        $host = $request->getSchemeAndHttpHost();

        return str_starts_with($target, $host.'/') || $target === $host;
    }

    public function resolve(?string $candidate, Request $request, string $fallback): string
    {
        if (null !== $candidate && $this->isSafe($request, $candidate)) {
            return $candidate;
        }

        return $fallback;
    }
}
