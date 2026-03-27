<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
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
        $user = $security->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (null !== $user->getId()) {
            $this->actorUserId = $user->getId();
        }
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $clientIp): void
    {
        $this->clientIp = $clientIp;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): void
    {
        $this->userAgent = $userAgent;
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
        return array_any($this->suppressedEntityAuditScopes, fn ($set): bool => isset($set[$entityClass]));
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
        $this->suppressedEntityAuditScopes = [];
    }

    public static function userIdFromInterface(?UserInterface $user): ?int
    {
        return $user instanceof User ? $user->getId() : null;
    }
}
