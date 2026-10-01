<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Shared\Domain\Traits\Blamable;
use App\Shared\Domain\Traits\HasPublicId;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[Audit\Audited]
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Department implements \Stringable
{
    use Blamable;
    use HasPublicId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

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

    /** @var Collection<int, DepartmentAlias> */
    #[ORM\OneToMany(targetEntity: DepartmentAlias::class, mappedBy: 'department')]
    private Collection $aliases;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now');
        $this->aliases = new ArrayCollection();
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
     * @return Collection<int, DepartmentAlias>
     */
    public function getAliases(): Collection
    {
        return $this->aliases;
    }

    public function addAlias(DepartmentAlias $alias): void
    {
        if (!$this->aliases->contains($alias)) {
            $this->aliases->add($alias);
        }
    }

    public function getAliasDetail(): string
    {
        $aliases = $this->aliases->toArray();
        usort($aliases, static fn (DepartmentAlias $left, DepartmentAlias $right): int => $left->getName() <=> $right->getName());
        $lines = [];
        foreach ($aliases as $alias) {
            $lines[] = sprintf('%s (%s, %s)', $alias->getName(), $alias->getClassification(), $alias->getSource());
        }

        return implode("\n", $lines);
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

    #[\Override]
    public function __toString(): string
    {
        return $this->name ?? 'No name';
    }
}
