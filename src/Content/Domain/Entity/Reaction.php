<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's reaction to one piece of content (#467). The target is referenced by type and id, not by a foreign key,
 * so new kinds of content can receive reactions without a schema change. Reactions of deleted targets are not shown
 * (only reactions of displayed targets are read) and are removed by the daily reaction cleanup.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reaction')]
#[ORM\UniqueConstraint(name: 'uniq_reaction_user_target', columns: ['user_id', 'target_type', 'target_id'])]
#[ORM\Index(name: 'idx_reaction_target', columns: ['target_type', 'target_id'])]
class Reaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 32, enumType: ReactionTargetType::class)]
        private ReactionTargetType $targetType,
        #[ORM\Column]
        private int $targetId,
        #[ORM\Column(length: 32, enumType: ReactionType::class)]
        private ReactionType $type,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTargetType(): ReactionTargetType
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    public function getType(): ReactionType
    {
        return $this->type;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Replaces the reaction with another one; a user has at most one reaction per target. */
    public function change(ReactionType $type, \DateTimeImmutable $at): void
    {
        $this->type = $type;
        $this->updatedAt = $at;
    }
}
