<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * A stored audit value prepared for display as plain text. Templates escape it; nothing here is HTML.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the audit entry templates.
 */
final readonly class AuditDetailValue
{
    /** Longer JSON is cut so one entry cannot blow up the page. */
    public const int MAX_LENGTH = 20_000;

    private function __construct(
        /** null, bool, number, string or json */
        public string $kind,
        public string $text,
        public bool $truncated = false,
    ) {
    }

    public static function of(mixed $value): self
    {
        if (null === $value) {
            return new self('null', '');
        }

        if (\is_bool($value)) {
            return new self('bool', $value ? 'true' : 'false');
        }

        if (\is_int($value) || \is_float($value)) {
            return new self('number', (string) $value);
        }

        if (\is_string($value)) {
            return self::cut('string', $value);
        }

        try {
            $json = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            return new self('string', '(invalid JSON data)');
        }

        return self::cut('json', $json);
    }

    private static function cut(string $kind, string $text): self
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return new self($kind, $text);
        }

        return new self($kind, mb_substr($text, 0, self::MAX_LENGTH), true);
    }
}
