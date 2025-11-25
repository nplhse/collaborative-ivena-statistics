<?php

namespace App\Entity;

use App\Repository\StateRepository;
use App\Shared\Domain\Traits\Blamable;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StateRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class State
{
    use Blamable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var Collection<int, DispatchArea>
     */
    #[ORM\OneToMany(targetEntity: DispatchArea::class, mappedBy: 'state')]
    private Collection $dispatchAreas;

    /**
     * @var Collection<int, Hospital>
     */
    #[ORM\OneToMany(targetEntity: Hospital::class, mappedBy: 'state')]
    private Collection $hospitals;

    #[ORM\Column()]
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

    public function __construct()
    {
        $this->dispatchAreas = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now');
        $this->hospitals = new ArrayCollection();
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

    /**
     * @return Collection<int, DispatchArea>
     */
    public function getDispatchAreas(): Collection
    {
        return $this->dispatchAreas;
    }

    public function addDispatchArea(DispatchArea $dispatchArea): static
    {
        if (!$this->dispatchAreas->contains($dispatchArea)) {
            $this->dispatchAreas->add($dispatchArea);
            $dispatchArea->setState($this);
        }

        return $this;
    }

    public function removeDispatchArea(DispatchArea $dispatchArea): static
    {
        if ($this->dispatchAreas->removeElement($dispatchArea)) {
            // set the owning side to null (unless already changed)
            if ($dispatchArea->getState() === $this) {
                $dispatchArea->setState(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Hospital>
     */
    public function getHospitals(): Collection
    {
        return $this->hospitals;
    }

    public function addHospital(Hospital $hospital): static
    {
        if (!$this->hospitals->contains($hospital)) {
            $this->hospitals->add($hospital);
            $hospital->setState($this);
        }

        return $this;
    }

    public function removeHospital(Hospital $hospital): static
    {
        if ($this->hospitals->removeElement($hospital)) {
            // set the owning side to null (unless already changed)
            if ($hospital->getState() === $this) {
                $hospital->setState(null);
            }
        }

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

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->setUpdatedAt(new \DateTimeImmutable('now'));
    }

    public function __toString(): string
    {
        return $this->name ?? 'No name';
    }
}
