<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\Content\Infrastructure\Repository\PageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'page')]
#[ORM\UniqueConstraint(name: 'uniq_page_path', columns: ['path'])]
#[ORM\UniqueConstraint(name: 'uniq_page_parent_slug', columns: ['parent_id', 'slug'])]
#[ORM\Index(name: 'idx_page_parent_sort', columns: ['parent_id', 'sort_order'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['path'], message: 'page.validation.path_unique')]
#[UniqueEntity(fields: ['parent', 'slug'], message: 'page.validation.parent_slug_unique')]
class Page implements \Stringable
{
    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_PUBLISHED = 'published';
    public const string VISIBILITY_PUBLIC = 'public';
    public const string VISIBILITY_AUTHENTICATED = 'authenticated';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $children;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'page.validation.slug_format',
    )]
    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180)]
    private ?string $slug = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    #[ORM\Column(length: 500)]
    private ?string $path = null;

    #[Assert\Choice(choices: [self::STATUS_DRAFT, self::STATUS_PUBLISHED])]
    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_DRAFT;

    #[Assert\Choice(choices: [self::VISIBILITY_PUBLIC, self::VISIBILITY_AUTHENTICATED])]
    #[ORM\Column(length: 32)]
    private string $visibility = self::VISIBILITY_PUBLIC;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * @var list<array{
     *   type: string,
     *   data: array<string, mixed>,
     *   enabled?: bool
     * }>
     */
    #[ORM\Column(type: 'json')]
    private array $content = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(self $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(self $child): self
    {
        if ($this->children->removeElement($child) && $child->getParent() === $this) {
            $child->setParent(null);
        }

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>, enabled?: bool}> $content
     */
    public function setContent(array $content): self
    {
        $this->content = $content;

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

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    #[Assert\Callback]
    public function validateHierarchy(ExecutionContextInterface $context): void
    {
        if (!$this->parent instanceof Page) {
            return;
        }

        if ($this->parent === $this) {
            $context->buildViolation('page.validation.parent_not_self')
                ->atPath('parent')
                ->addViolation();

            return;
        }

        $ancestor = $this->parent;
        while ($ancestor instanceof self) {
            if ($ancestor === $this) {
                $context->buildViolation('page.validation.no_cycles')
                    ->atPath('parent')
                    ->addViolation();

                return;
            }

            $ancestor = $ancestor->getParent();
        }
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->title ?? 'Untitled page';
    }
}
