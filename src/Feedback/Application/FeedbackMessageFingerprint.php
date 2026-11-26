<?php

declare(strict_types=1);

namespace App\Feedback\Application;

/**
 * Fingerprint for spotting repeated messages: lowercased, links and digits removed, whitespace collapsed,
 * hashed with SHA-256. Messages that differ only in a link, a number or spacing get the same fingerprint.
 */
final readonly class FeedbackMessageFingerprint
{
    /** @return non-empty-string|null null when nothing is left after normalising */
    public static function fromMessage(string $message): ?string
    {
        $normalized = mb_strtolower($message);
        $normalized = preg_replace('/\b(?:https?:\/\/|www\.)\S+/iu', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\p{N}+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        if ('' === $normalized) {
            return null;
        }

        return hash('sha256', $normalized);
    }
}
