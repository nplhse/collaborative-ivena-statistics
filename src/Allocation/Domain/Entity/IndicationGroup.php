<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Shared\Domain\Traits\Blamable;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[Audit\Audited]
#[ORM\Entity(repositoryClass: IndicationGroupRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class IndicationGroup implements \Stringable
{
    use Blamable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(nullable: true)]
    private ?int $sortOrder = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @psalm-suppress PropertyNotSetInConstructor */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected ?User $updatedBy = null;

    /** @var Collection<int, IndicationNormalized> */
    #[ORM\ManyToMany(targetEntity: IndicationNormalized::class, inversedBy: 'groups')]
    #[ORM\JoinTable(name: 'indication_group_indication_normalized')]
    private Collection $indications;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now');
        $this->indications = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(?int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /** @return Collection<int, IndicationNormalized> */
    public function getIndications(): Collection
    {
        return $this->indications;
    }

    public function addIndication(IndicationNormalized $indication): static
    {
        if (!$this->indications->contains($indication)) {
            $this->indications->add($indication);
        }

        return $this;
    }

    public function removeIndication(IndicationNormalized $indication): static
    {
        $this->indications->removeElement($indication);

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->setUpdatedAt(new \DateTimeImmutable('now'));
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->name ?? 'Indication group';
    }
}
