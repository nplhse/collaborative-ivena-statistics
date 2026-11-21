<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A signed-in user's answer to "Was this article helpful?" for one help article (#596). One answer per user and
 * article; answering again changes it. Comments on unhelpful articles go to the feedback inbox, not here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'help_feedback')]
#[ORM\UniqueConstraint(name: 'uniq_help_feedback_page_user', columns: ['page_id', 'user_id'])]
class HelpFeedback
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
        private Page $page,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column]
        private bool $helpful,
        /** Language version the user read. */
        #[ORM\Column(length: 8)]
        private string $locale,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPage(): Page
    {
        return $this->page;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isHelpful(): bool
    {
        return $this->helpful;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function answer(bool $helpful, string $locale, \DateTimeImmutable $at): void
    {
        $this->helpful = $helpful;
        $this->locale = $locale;
        $this->updatedAt = $at;
    }
}
