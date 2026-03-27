<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Entity;

use App\Shared\Infrastructure\Audit\Repository\AuditEntryRepository;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * EasyAdmin / Doctrine metadata use these getters; not all appear as direct calls in static analysis.
 *
 * @psalm-suppress ClassMustBeFinal
 */
#[ORM\Entity(repositoryClass: AuditEntryRepository::class)]
#[ORM\Table(name: 'audit_log')]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, mixed>      $changes
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(length: 128)]
        private string $requestId,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $actor,
        #[ORM\Column(length: 32)]
        private string $origin,
        #[ORM\Column(length: 16)]
        private string $action,
        #[ORM\Column(length: 255)]
        private string $entityClass,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $entityId,
        #[ORM\Column(type: Types::JSON)]
        private array $changes,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $metadata,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getId(): ?int
    {
        return $this->id;
    }

    public function setEntityId(?string $entityId): void
    {
        $this->entityId = $entityId;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getActor(): ?User
    {
        return $this->actor;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getOrigin(): string
    {
        return $this->origin;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }
}
