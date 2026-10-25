<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pagination;

/**
 * Encodes the boundary row of a keyset page.
 *
 * A forward cursor holds the last row of a page and points to the rows after it;
 * a backward cursor holds the first row and points to the rows before it.
 */
final class CursorCodec
{
    private const int VERSION = 2;

    /** Version 1 cursors had no direction and always pointed forward. */
    private const int LEGACY_VERSION = 1;

    public function encode(string $sortBy, string $orderBy, string|int $sortValue, int $id, bool $forward = true): string
    {
        $payload = [
            'v' => self::VERSION,
            'sortBy' => $sortBy,
            'orderBy' => $orderBy,
            'sortValue' => $sortValue,
            'id' => $id,
            'direction' => $forward ? 'next' : 'prev',
        ];

        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{sortBy: string, orderBy: string, sortValue: string|int, id: int, forward: bool}
     */
    public function decode(string $cursor): array
    {
        $decoded = base64_decode($cursor, true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Cursor is not valid base64.');
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Cursor payload is not valid JSON.', $exception->getCode(), previous: $exception);
        }

        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('Cursor payload must be an object.');
        }

        $requiredKeys = ['v', 'sortBy', 'orderBy', 'sortValue', 'id'];
        foreach ($requiredKeys as $key) {
            if (!\array_key_exists($key, $payload)) {
                throw new \InvalidArgumentException(sprintf('Cursor payload misses key "%s".', $key));
            }
        }

        if (self::VERSION !== $payload['v'] && self::LEGACY_VERSION !== $payload['v']) {
            throw new \InvalidArgumentException('Unsupported cursor version.');
        }

        if (!\is_string($payload['sortBy']) || !\is_string($payload['orderBy']) || !\is_int($payload['id'])) {
            throw new \InvalidArgumentException('Cursor payload has invalid field types.');
        }

        if (!\is_string($payload['sortValue']) && !\is_int($payload['sortValue'])) {
            throw new \InvalidArgumentException('Cursor sortValue has invalid type.');
        }

        $direction = self::LEGACY_VERSION === $payload['v'] ? 'next' : ($payload['direction'] ?? null);
        if ('next' !== $direction && 'prev' !== $direction) {
            throw new \InvalidArgumentException('Cursor direction must be "next" or "prev".');
        }

        return [
            'sortBy' => $payload['sortBy'],
            'orderBy' => $payload['orderBy'],
            'sortValue' => $payload['sortValue'],
            'id' => $payload['id'],
            'forward' => 'next' === $direction,
        ];
    }
}
