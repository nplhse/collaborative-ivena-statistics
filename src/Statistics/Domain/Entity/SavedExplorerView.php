<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Entity;

use App\Shared\Domain\Traits\Blamable;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedExplorerViewRepository::class)]
#[ORM\Table(name: 'saved_explorer_view')]
#[ORM\UniqueConstraint(name: 'uniq_saved_explorer_view_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class SavedExplorerView
{
    use Blamable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 80)]
    private string $category;

    /** @var array<string, mixed> */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON)]
    private array $configJson = [];

    #[ORM\Column]
    private bool $isSystem = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @psalm-suppress PropertyNotSetInConstructor */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $updatedBy = null;

    /**
     * @param array<string, mixed> $configJson
     */
    public function __construct(
        ?string $slug,
        string $title,
        string $category,
        array $configJson,
        ?string $description = null,
        bool $isSystem = false,
    ) {
        $this->slug = $slug;
        $this->title = $title;
        $this->category = $category;
        $this->configJson = $configJson;
        $this->description = $description;
        $this->isSystem = $isSystem;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigJson(): array
    {
        return $this->configJson;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function wasCreatedBy(User $user): bool
    {
        $creator = $this->getCreatedBy();

        return $creator instanceof User
            && null !== $creator->getId()
            && null !== $user->getId()
            && $creator->getId() === $user->getId();
    }

    public function isEditableBy(?User $user): bool
    {
        return !$this->isSystem
            && $user instanceof User
            && $this->wasCreatedBy($user);
    }

    public function isAccessibleBy(?User $user): bool
    {
        if ($this->isSystem) {
            return true;
        }

        return $user instanceof User && $this->wasCreatedBy($user);
    }

    /**
     * @param array<string, mixed> $configJson
     */
    public function update(
        string $title,
        string $category,
        array $configJson,
        ?string $description = null,
    ): void {
        $this->title = $title;
        $this->category = $category;
        $this->configJson = $configJson;
        $this->description = $description;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
