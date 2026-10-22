<?php

declare(strict_types=1);

namespace App\Notification\Application\Inbox;

/**
 * Keyset position in the inbox: entries older than (createdAt, id).
 */
final readonly class InboxCursor
{
    private const int VERSION = 1;

    public function __construct(
        public \DateTimeImmutable $createdAt,
        public int $id,
    ) {
    }

    public static function after(InboxEntry $entry): self
    {
        return new self($entry->createdAt, $entry->id);
    }

    public function encode(): string
    {
        $payload = json_encode([
            'v' => self::VERSION,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'id' => $this->id,
        ], \JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Returns null for anything that is not a cursor produced by encode().
     */
    public static function tryDecode(?string $cursor): ?self
    {
        if (null === $cursor || '' === $cursor) {
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
            || !\is_string($payload['createdAt'] ?? null)
            || !\is_int($payload['id'] ?? null)
            || $payload['id'] < 1) {
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $payload['createdAt']);

        return false === $createdAt ? null : new self($createdAt, $payload['id']);
    }
}
