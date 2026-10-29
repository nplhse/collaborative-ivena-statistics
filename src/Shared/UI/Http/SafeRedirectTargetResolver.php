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

        // Browsers read a backslash as a slash and drop tabs and line breaks, so "/\host" or
        // "/<tab>/host" would leave the site.
        if (1 === preg_match('/[\\\\\x00-\x1F\x7F]/', $target)) {
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
