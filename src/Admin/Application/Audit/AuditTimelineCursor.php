<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Keyset position in the audit timeline: (occurredAt, id) of the last entry of a page.
 * The next page continues with the entries before it. Entries written later never move an open page.
 */
final readonly class AuditTimelineCursor
{
    private const int VERSION = 1;

    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public int $id,
    ) {
    }

    public static function after(AuditTimelineEntry $entry): self
    {
        return new self($entry->occurredAt, $entry->id);
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the audit timeline template for the next-page link. */
    public function encode(): string
    {
        $payload = json_encode([
            'v' => self::VERSION,
            'at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'id' => $this->id,
        ], \JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Returns null for anything that is not a cursor produced by encode().
     */
    public static function tryDecode(?string $cursor): ?self
    {
        if (null === $cursor || '' === $cursor || \strlen($cursor) > 200) {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $json) {
            return null;
        }

        try {
            $payload = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($payload)
            || self::VERSION !== ($payload['v'] ?? null)
            || !\is_string($payload['at'] ?? null)
            || !\is_int($payload['id'] ?? null)
            || $payload['id'] < 1) {
            return null;
        }

        $occurredAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $payload['at']);

        return false === $occurredAt ? null : new self($occurredAt, $payload['id']);
    }
}
