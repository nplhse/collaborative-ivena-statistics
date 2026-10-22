<?php

declare(strict_types=1);

namespace App\Notification\UI\Http;

/**
 * Accepts only same-site paths as return targets, so actions cannot be abused as open redirects.
 */
final class SafeTargetPath
{
    public static function sanitize(?string $target): ?string
    {
        if (null === $target) {
            return null;
        }

        if (!str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, '\\')) {
            return null;
        }

        return $target;
    }
}
