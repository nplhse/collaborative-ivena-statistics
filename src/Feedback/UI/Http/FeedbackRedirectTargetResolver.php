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

        if ('' === $target || str_contains($target, "\0") || preg_match('/[\r\n]/', $target)) {
            return '/';
        }

        return $target;
    }
}
