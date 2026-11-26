<?php

declare(strict_types=1);

namespace App\Onboarding\Domain\Entity;

use App\Onboarding\Infrastructure\Repository\UserWhatsNewDismissalRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user hid the "What's new for you" card of a release (#1485).
 */
#[ORM\Entity(repositoryClass: UserWhatsNewDismissalRepository::class)]
#[ORM\Table(name: 'user_whats_new_dismissal')]
#[ORM\UniqueConstraint(name: 'uniq_user_whats_new_dismissal', columns: ['user_id', 'release_key'])]
class UserWhatsNewDismissal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $releaseKey;

    #[ORM\Column]
    private \DateTimeImmutable $dismissedAt;

    public function __construct(User $user, string $releaseKey, \DateTimeImmutable $dismissedAt)
    {
        $this->user = $user;
        $this->releaseKey = $releaseKey;
        $this->dismissedAt = $dismissedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReleaseKey(): string
    {
        return $this->releaseKey;
    }

    public function getDismissedAt(): \DateTimeImmutable
    {
        return $this->dismissedAt;
    }
}
