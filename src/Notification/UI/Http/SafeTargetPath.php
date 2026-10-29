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

        // A backslash or control character could turn the path into a link to another host.
        if (!str_starts_with($target, '/') || str_starts_with($target, '//') || 1 === preg_match('/[\\\\\x00-\x1F\x7F]/', $target)) {
            return null;
        }

        return $target;
    }
}
