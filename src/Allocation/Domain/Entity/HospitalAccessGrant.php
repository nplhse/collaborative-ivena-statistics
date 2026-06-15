<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Repository\HospitalAccessGrantRepository;
use App\Shared\Domain\Traits\Blamable;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[Audit\Audited]
#[ORM\Entity(repositoryClass: HospitalAccessGrantRepository::class)]
#[ORM\Table(name: 'hospital_access_grant')]
#[ORM\UniqueConstraint(name: 'uniq_hospital_access_grant_hospital_user', fields: ['hospital', 'user'])]
#[ORM\HasLifecycleCallbacks]
class HospitalAccessGrant
{
    use Blamable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'accessGrants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Hospital $hospital = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private int $permissions = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected ?User $updatedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): ?Hospital
    {
        return $this->hospital;
    }

    public function setHospital(Hospital $hospital): static
    {
        $this->hospital = $hospital;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPermissions(): int
    {
        return $this->permissions;
    }

    public function setPermissions(int $permissions): static
    {
        $normalized = HospitalPermissionMask::normalize($permissions);
        if (!HospitalPermissionMask::isValid($normalized)) {
            throw new \InvalidArgumentException('Invalid hospital permission mask.');
        }

        $this->permissions = $normalized;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }
}
