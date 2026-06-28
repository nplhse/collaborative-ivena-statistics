<?php

declare(strict_types=1);

namespace App\Onboarding\Domain\Entity;

use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Onboarding\Infrastructure\Repository\UserOnboardingStepRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserOnboardingStepRepository::class)]
#[ORM\Table(name: 'user_onboarding_step')]
#[ORM\UniqueConstraint(name: 'uniq_user_onboarding_step', columns: ['user_id', 'step_key'])]
class UserOnboardingStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 50, enumType: OnboardingStepKey::class)]
    private OnboardingStepKey $stepKey;

    #[ORM\Column]
    private \DateTimeImmutable $completedAt;

    public function __construct(User $user, OnboardingStepKey $stepKey, ?\DateTimeImmutable $completedAt = null)
    {
        $this->user = $user;
        $this->stepKey = $stepKey;
        $this->completedAt = $completedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStepKey(): OnboardingStepKey
    {
        return $this->stepKey;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }
}
