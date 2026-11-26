<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class AuditContext
{
    private ?string $requestId = null;

    private ?string $origin = null;

    private ?int $actorUserId = null;

    /** @var list<array{name: string, metadata: array<string, mixed>}> */
    private array $intentStack = [];

    private ?string $clientIp = null;

    private ?string $userAgent = null;

    private ?string $upstreamRequestId = null;

    private ?string $sessionRef = null;

    /** @var list<array<class-string, true>> */
    private array $suppressedEntityAuditScopes = [];

    public const string ORIGIN_HTTP = 'http';

    public const string ORIGIN_CLI = 'cli';

    public const string ORIGIN_MESSENGER = 'messenger';

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function ensureRequestId(callable $generator): string
    {
        if (null === $this->requestId || '' === $this->requestId) {
            $this->requestId = $generator();
        }

        return $this->requestId;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    public function setOrigin(?string $origin): void
    {
        $this->origin = $origin;
    }

    public function getActorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function setActorUserId(?int $actorUserId): void
    {
        $this->actorUserId = $actorUserId;
    }

    public function applySecurityUser(Security $security): void
    {
        $token = $security->getToken();
        if ($token instanceof SwitchUserToken) {
            $user = $token->getOriginalToken()->getUser();
        } else {
            $user = $security->getUser();
        }

        if (!$user instanceof User) {
            return;
        }

        if (null !== $user->getId()) {
            $this->actorUserId = $user->getId();
        }
    }

    public function setClientIp(?string $clientIp): void
    {
        $this->clientIp = $clientIp;
    }

    public function setUserAgent(?string $userAgent): void
    {
        $this->userAgent = $userAgent;
    }

    /**
     * Well-formed X-Request-Id sent by the client or a proxy. Kept for tracing only; it never becomes the request id.
     */
    public function getUpstreamRequestId(): ?string
    {
        return $this->upstreamRequestId;
    }

    public function setUpstreamRequestId(?string $upstreamRequestId): void
    {
        $this->upstreamRequestId = $upstreamRequestId;
    }

    /**
     * Random reference of the authenticated session (ADR 025). Never the session id.
     */
    public function getSessionRef(): ?string
    {
        return $this->sessionRef;
    }

    public function setSessionRef(?string $sessionRef): void
    {
        $this->sessionRef = $sessionRef;
    }

    /**
     * Request context shared by all audit producers: client IP, user agent and upstream request id, where known.
     *
     * @return array<string, string>
     */
    public function requestMetadata(): array
    {
        $metadata = [];

        if (null !== $this->clientIp && '' !== $this->clientIp) {
            $metadata['clientIp'] = $this->clientIp;
        }

        if (null !== $this->userAgent && '' !== $this->userAgent) {
            $metadata['userAgent'] = $this->userAgent;
        }

        if (null !== $this->upstreamRequestId && '' !== $this->upstreamRequestId) {
            $metadata['upstreamRequestId'] = $this->upstreamRequestId;
        }

        return $metadata;
    }

    /** @param list<class-string> $entityClasses */
    public function pushSuppressedEntityAudit(array $entityClasses): void
    {
        $set = [];
        foreach ($entityClasses as $class) {
            $set[$class] = true;
        }
        $this->suppressedEntityAuditScopes[] = $set;
    }

    public function popSuppressedEntityAudit(): void
    {
        array_pop($this->suppressedEntityAuditScopes);
    }

    public function isEntityAuditSuppressed(string $entityClass): bool
    {
        return array_any($this->suppressedEntityAuditScopes, fn (array $set): bool => isset($set[$entityClass]));
    }

    /** @param array<string, mixed> $metadata */
    public function beginIntent(string $name, array $metadata = []): void
    {
        $this->intentStack[] = ['name' => $name, 'metadata' => $metadata];
    }

    public function endIntent(): void
    {
        array_pop($this->intentStack);
    }

    /** @return array{name: string, metadata: array<string, mixed>}|null */
    public function getCurrentIntent(): ?array
    {
        if ([] === $this->intentStack) {
            return null;
        }

        return $this->intentStack[\count($this->intentStack) - 1];
    }

    public function reset(): void
    {
        $this->requestId = null;
        $this->origin = null;
        $this->actorUserId = null;
        $this->intentStack = [];
        $this->clientIp = null;
        $this->userAgent = null;
        $this->upstreamRequestId = null;
        $this->sessionRef = null;
        $this->suppressedEntityAuditScopes = [];
    }

    public static function userIdFromInterface(?UserInterface $user): ?int
    {
        return $user instanceof User ? $user->getId() : null;
    }
}
