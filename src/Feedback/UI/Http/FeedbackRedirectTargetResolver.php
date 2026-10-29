<?php

declare(strict_types=1);

namespace App\Feedback\UI\Http;

final class FeedbackRedirectTargetResolver
{
    public function resolve(string $target): string
    {
        $target = trim($target);
        if ('' === $target || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return '/';
        }

        $fragmentPos = strpos($target, '#');
        if (false !== $fragmentPos) {
            $target = substr($target, 0, $fragmentPos);
        }

        // A backslash or control character could turn the path into a link to another host.
        if ('' === $target || 1 === preg_match('/[\\\\\x00-\x1F\x7F]/', $target)) {
            return '/';
        }

        return $target;
    }
}
