<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Server-side filters of the audit timeline. Every criterion narrows the entries; null means "any".
 */
final readonly class AuditTimelineFilter
{
    public const array ORIGINS = ['http', 'cli', 'messenger'];

    public const array OUTCOMES = ['success', 'blocked', 'failed'];

    /** Actions the audit log writes (see docs/05-operations/audit-log.md). */
    public const array ACTIONS = ['create', 'update', 'delete', 'export', 'download', 'impersonate', 'impersonate_exit'];

    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?int $actorId = null,
        /** Only entries without actor: anonymous, console and system activity. */
        public bool $withoutActor = false,
        public ?string $origin = null,
        public ?string $action = null,
        public ?string $intent = null,
        public ?string $entityClass = null,
        public ?string $entityId = null,
        public ?string $requestId = null,
        public ?string $sessionRef = null,
        public ?string $outcome = null,
    ) {
    }

    /**
     * Builds a filter from query parameters. Unknown or malformed values are ignored, never an error.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $actor = self::string($query, 'actor');
        $origin = self::string($query, 'origin');
        $outcome = self::string($query, 'outcome');

        return new self(
            from: self::date($query, 'from', '!Y-m-d\TH:i'),
            to: self::date($query, 'to', '!Y-m-d\TH:i'),
            actorId: null !== $actor && ctype_digit($actor) ? (int) $actor : null,
            withoutActor: 'none' === $actor,
            origin: \in_array($origin, self::ORIGINS, true) ? $origin : null,
            action: \in_array($action = self::string($query, 'action'), self::ACTIONS, true) ? $action : null,
            intent: self::token($query, 'intent', 128),
            entityClass: self::token($query, 'entity', 255),
            entityId: self::token($query, 'entityId', 64),
            requestId: self::token($query, 'request', 128),
            sessionRef: self::token($query, 'session', 36),
            outcome: \in_array($outcome, self::OUTCOMES, true) ? $outcome : null,
        );
    }

    /**
     * The same filter, narrowed to one request.
     */
    public function withRequestId(string $requestId): self
    {
        return new self(
            $this->from,
            $this->to,
            $this->actorId,
            $this->withoutActor,
            $this->origin,
            $this->action,
            $this->intent,
            $this->entityClass,
            $this->entityId,
            $requestId,
            $this->sessionRef,
            $this->outcome,
        );
    }

    /**
     * Query parameters that reproduce this filter (for links and pagination).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [
            'from' => $this->from?->format('Y-m-d\TH:i'),
            'to' => $this->to?->format('Y-m-d\TH:i'),
            'actor' => $this->withoutActor ? 'none' : (null === $this->actorId ? null : (string) $this->actorId),
            'origin' => $this->origin,
            'action' => $this->action,
            'intent' => $this->intent,
            'entity' => $this->entityClass,
            'entityId' => $this->entityId,
            'request' => $this->requestId,
            'session' => $this->sessionRef,
            'outcome' => $this->outcome,
        ];

        return array_filter($query, static fn (?string $value): bool => null !== $value);
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by the audit timeline templates. */
    public function isEmpty(): bool
    {
        return [] === $this->toQuery();
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function string(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * Identifiers, class names and intents: printable characters only, bounded length.
     *
     * @param array<string, mixed> $query
     */
    private static function token(array $query, string $key, int $maxLength): ?string
    {
        $value = self::string($query, $key);
        if (null === $value || \strlen($value) > $maxLength || 1 !== preg_match('/^[\w.\\\\:-]+$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function date(array $query, string $key, string $format): ?\DateTimeImmutable
    {
        $value = self::string($query, $key);
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat($format, $value);

        return false === $date ? null : $date;
    }
}
