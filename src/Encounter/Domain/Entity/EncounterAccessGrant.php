<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * Explicit case-level access of one user to the encounter data of one hospital (ADR 027).
 *
 * Independent of hospital ownership and hospital access grants. Administrators need no stored grant;
 * EncounterVoter treats them as holding every action. A grant always holds at least one action; REVIEW
 * implies VIEW_CASES (also enforced by a check constraint).
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_access_grant')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_access_grant_hospital_user', fields: ['hospital', 'user'])]
#[ORM\Index(name: 'idx_encounter_access_grant_user', fields: ['user'])]
#[ORM\HasLifecycleCallbacks]
class EncounterAccessGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Hospital $hospital;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'can_upload', options: ['default' => false])]
    private bool $upload = false;

    #[ORM\Column(name: 'can_review', options: ['default' => false])]
    private bool $review = false;

    #[ORM\Column(name: 'can_view_cases', options: ['default' => false])]
    private bool $viewCases = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    /**
     * @param iterable<EncounterGrantAction> $actions
     */
    public function __construct(Hospital $hospital, User $user, iterable $actions, ?User $createdBy = null)
    {
        $this->hospital = $hospital;
        $this->user = $user;
        $this->createdBy = $createdBy;
        $this->createdAt = Clock::get()->now();
        $this->applyActions($actions);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): Hospital
    {
        return $this->hospital;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function allows(EncounterGrantAction $action): bool
    {
        return match ($action) {
            EncounterGrantAction::Upload => $this->upload,
            EncounterGrantAction::Review => $this->review,
            EncounterGrantAction::ViewCases => $this->viewCases,
        };
    }

    /**
     * @return list<EncounterGrantAction>
     */
    public function getActions(): array
    {
        return array_values(array_filter(
            EncounterGrantAction::cases(),
            $this->allows(...),
        ));
    }

    /**
     * @param iterable<EncounterGrantAction> $actions
     */
    public function changeActions(iterable $actions, ?User $changedBy = null): void
    {
        $this->applyActions($actions);
        $this->updatedBy = $changedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = Clock::get()->now();
    }

    /**
     * @param iterable<EncounterGrantAction> $actions
     */
    private function applyActions(iterable $actions): void
    {
        $upload = false;
        $review = false;
        $viewCases = false;
        foreach ($actions as $action) {
            match ($action) {
                EncounterGrantAction::Upload => $upload = true,
                EncounterGrantAction::Review => $review = true,
                EncounterGrantAction::ViewCases => $viewCases = true,
            };
        }

        if (!$upload && !$review && !$viewCases) {
            throw new \InvalidArgumentException('An encounter access grant needs at least one action.');
        }

        $this->upload = $upload;
        $this->review = $review;
        // REVIEW implies VIEW_CASES (ADR 027).
        $this->viewCases = $viewCases || $review;
    }
}
