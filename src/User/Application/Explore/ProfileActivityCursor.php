<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

final readonly class ProfileActivityCursor
{
    private const int VERSION = 2;

    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public int $id,
        public bool $joined,
    ) {
    }

    public static function fromActivity(ProfileActivity $activity): self
    {
        return new self(
            $activity->occurredAt,
            (int) $activity->stableId,
            ProfileActivityType::JOINED === $activity->type,
        );
    }

    public static function padId(int $id): string
    {
        return sprintf('%020d', $id);
    }

    public function encode(): string
    {
        $payload = [
            'v' => self::VERSION,
            'occurredAt' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'id' => $this->id,
            'joined' => $this->joined,
        ];

        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function decode(string $cursor): self
    {
        $decoded = base64_decode($cursor, true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Cursor is not valid base64.');
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Cursor payload is not valid JSON.', 0, $exception);
        }

        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('Cursor payload must be an object.');
        }

        foreach (['v', 'occurredAt', 'id', 'joined'] as $key) {
            if (!\array_key_exists($key, $payload)) {
                throw new \InvalidArgumentException(sprintf('Cursor payload misses key "%s".', $key));
            }
        }

        if (self::VERSION !== $payload['v']) {
            throw new \InvalidArgumentException('Unsupported cursor version.');
        }

        if (!\is_string($payload['occurredAt']) || !\is_int($payload['id']) || !\is_bool($payload['joined'])) {
            throw new \InvalidArgumentException('Cursor payload has invalid field types.');
        }

        if ($payload['id'] < 1) {
            throw new \InvalidArgumentException('Cursor payload has invalid id.');
        }

        try {
            $occurredAt = new \DateTimeImmutable($payload['occurredAt']);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('Cursor payload has invalid occurredAt.', 0, $exception);
        }

        return new self($occurredAt, $payload['id'], $payload['joined']);
    }

    public static function tryDecode(?string $cursor): ?self
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        try {
            return self::decode($cursor);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function frameId(string $encodedCursor): string
    {
        return 'profile-activity-after-'.rtrim(strtr($encodedCursor, '+/', '-_'), '=');
    }
}
