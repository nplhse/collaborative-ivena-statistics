<?php

declare(strict_types=1);

namespace App\User\Domain\Entity;

use App\User\Domain\Enum\UserActivityType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** @psalm-suppress ClassMustBeFinal */
#[ORM\Entity]
#[ORM\Table(name: 'user_activity')]
#[ORM\UniqueConstraint(name: 'uniq_user_activity_deduplication_key', columns: ['deduplication_key'])]
#[ORM\Index(name: 'idx_user_activity_feed', columns: ['user_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_user_activity_project_feed', columns: ['occurred_at', 'id'])]
class UserActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, enumType: UserActivityType::class)]
    private UserActivityType $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 191)]
    private string $deduplicationKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        User $user,
        UserActivityType $type,
        \DateTimeImmutable $occurredAt,
        string $deduplicationKey,
        array $metadata = [],
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->user = $user;
        $this->type = $type;
        $this->occurredAt = $occurredAt;
        $this->deduplicationKey = $deduplicationKey;
        $this->metadata = $metadata;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): UserActivityType
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getDeduplicationKey(): string
    {
        return $this->deduplicationKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
