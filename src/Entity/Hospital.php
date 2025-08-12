<?php

namespace App\Entity;

use App\Entity\Traits\Blamable;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\Repository\HospitalRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HospitalRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Hospital
{
    use Blamable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(inversedBy: 'hospitals')]
    private ?User $owner = null;

    #[ORM\ManyToOne(inversedBy: 'hospitals')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DispatchArea $dispatchArea = null;

    #[ORM\ManyToOne(inversedBy: 'hospitals')]
    #[ORM\JoinColumn(nullable: false)]
    private ?State $state = null;

    #[ORM\Embedded(class: Address::class)]
    private Address $address;

    #[ORM\Column(enumType: HospitalLocation::class)]
    private ?HospitalLocation $location = null;

    #[ORM\Column(enumType: HospitalTier::class)]
    private ?HospitalTier $tier = null;

    #[ORM\Column(enumType: HospitalSize::class)]
    private ?HospitalSize $size = null;

    #[ORM\Column]
    private ?int $beds = null;

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
        $this->createdAt = new \DateTimeImmutable('now');
        $this->address = new Address();
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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

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

    public function getState(): ?State
    {
        return $this->state;
    }

    public function setState(?State $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getAddress(): Address
    {
        return $this->address;
    }

    public function setAddress(Address $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getLocation(): ?HospitalLocation
    {
        return $this->location;
    }

    public function setLocation(HospitalLocation $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getTier(): ?HospitalTier
    {
        return $this->tier;
    }

    public function setTier(HospitalTier $tier): static
    {
        $this->tier = $tier;

        return $this;
    }

    public function getSize(): ?HospitalSize
    {
        return $this->size;
    }

    public function setSize(HospitalSize $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getBeds(): ?int
    {
        return $this->beds;
    }

    public function setBeds(int $beds): static
    {
        $this->beds = $beds;

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
