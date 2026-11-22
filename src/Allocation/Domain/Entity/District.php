<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use App\Allocation\Infrastructure\Repository\DistrictRepository;
use App\Shared\Domain\Traits\Blamable;
use App\Shared\Domain\Traits\HasRequiredPublicId;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * A German district (Kreis or kreisfreie Stadt), identified by its official key (AGS). It belongs to at most one
 * dispatch area. The state key is kept without a relation to `State`, so a district outside the installation's
 * states does not create an empty state (ADR 041).
 */
#[Audit\Audited]
#[ORM\Entity(repositoryClass: DistrictRepository::class)]
#[ORM\Index(name: 'idx_district_dispatch_area', columns: ['dispatch_area_id'])]
#[ORM\UniqueConstraint(name: 'uniq_district_ags', columns: ['ags'])]
#[ORM\HasLifecycleCallbacks]
class District implements \Stringable
{
    use Blamable;
    use HasRequiredPublicId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 5)]
    private string $ags;

    #[ORM\Column(length: 2)]
    private string $stateCode;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $kind = null;

    #[ORM\ManyToOne(targetEntity: DispatchArea::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DispatchArea $dispatchArea = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Nullable unlike its siblings: the one-time takeover runs during a deployment, without a signed-in user.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected ?User $updatedBy = null;

    public function __construct(string $ags, string $name)
    {
        if (1 !== preg_match('/^\d{5}$/D', $ags)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a five-digit district key (AGS).', $ags));
        }

        $this->ags = $ags;
        $this->stateCode = substr($ags, 0, 2);
        $this->name = $name;
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgs(): string
    {
        return $this->ags;
    }

    public function getStateCode(): string
    {
        return $this->stateCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(?string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getDispatchArea(): ?DispatchArea
    {
        return $this->dispatchArea;
    }

    public function setDispatchArea(?DispatchArea $dispatchArea): static
    {
        $this->dispatchArea = $dispatchArea;

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
        $this->updatedAt = Clock::get()->now();
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->name;
    }
}
