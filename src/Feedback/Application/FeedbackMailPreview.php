<?php

declare(strict_types=1);

namespace App\Feedback\Application;

/**
 * Builds a deliverability-safe short preview of user feedback for outbound admin mail.
 */
final readonly class FeedbackMailPreview
{
    public const int MAX_LENGTH = 250;

    public static function fromMessage(string $message): string
    {
        $stripped = preg_replace('/\b(?:https?:\/\/|www\.)\S+/iu', '', $message) ?? $message;
        $normalized = trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);

        if (mb_strlen($normalized) <= self::MAX_LENGTH) {
            return $normalized;
        }

        return mb_substr($normalized, 0, self::MAX_LENGTH).'…';
    }
}
