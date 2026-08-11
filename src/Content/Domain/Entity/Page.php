<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Repository\PageRepository;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Audit\Audited]
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'page')]
#[ORM\UniqueConstraint(name: 'uniq_page_path', columns: ['path'])]
#[ORM\UniqueConstraint(name: 'uniq_page_parent_slug', columns: ['parent_id', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_page_key', columns: ['key'])]
#[ORM\Index(name: 'idx_page_parent_sort', columns: ['parent_id', 'sort_order'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['path'], message: 'page.validation.path_unique')]
#[UniqueEntity(fields: ['parent', 'slug'], message: 'page.validation.parent_slug_unique')]
#[UniqueEntity(fields: ['key'], message: 'page.validation.key_unique')]
class Page implements \Stringable
{
    /** Transitional until Phase 4 — prefer PageTranslation::STATUS_DRAFT. */
    public const string STATUS_DRAFT = 'draft';

    /** Transitional until Phase 4 — prefer PageTranslation::STATUS_PUBLISHED. */
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

    /** @var Collection<int, PageTranslation> */
    #[ORM\OneToMany(targetEntity: PageTranslation::class, mappedBy: 'page', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['locale' => 'ASC'])]
    private Collection $translations;

    // Transitional legacy columns until Phase 4 — prefer PageTranslation accessors.
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private ?string $title = null;

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

    #[ORM\Column(nullable: true, enumType: PageKey::class)]
    private ?PageKey $key = null;

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
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON)]
    private array $content = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->translations = new ArrayCollection();
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

    /** @return Collection<int, PageTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(PageTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setPage($this);
        }

        return $this;
    }

    public function removeTranslation(PageTranslation $translation): self
    {
        if ($this->translations->removeElement($translation) && $translation->getPage() === $this) {
            $translation->setPage(null);
        }

        return $this;
    }

    public function translation(string $locale): ?PageTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function hasTranslation(string $locale): bool
    {
        return $this->translation($locale) instanceof PageTranslation;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::getTitle().
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::setTitle().
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::getSlug().
     */
    public function getSlug(): ?string
    {
        return $this->slug;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::setSlug().
     */
    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::getPath().
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::setPath().
     */
    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getKey(): ?PageKey
    {
        return $this->key;
    }

    public function setKey(?PageKey $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::getStatus().
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::setStatus().
     */
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
     * Transitional until Phase 4 cleanup — prefer PageTranslation::getContent().
     *
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::setContent().
     *
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

    /**
     * Transitional until Phase 4 cleanup — prefer PageTranslation::isPublished() / hasPublishedTranslation().
     */
    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    public function hasPublishedTranslation(): bool
    {
        foreach ($this->translations as $translation) {
            if ($translation->isPublished()) {
                return true;
            }
        }

        return false;
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
        if (null !== $this->title && '' !== $this->title) {
            return $this->title;
        }

        foreach ($this->translations as $translation) {
            $title = $translation->getTitle();
            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        return 'Untitled page';
    }
}
