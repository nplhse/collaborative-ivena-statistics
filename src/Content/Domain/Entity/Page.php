<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\Content\Domain\Enum\PageKey;
use App\Content\Domain\Enum\PageType;
use App\Content\Infrastructure\Repository\PageRepository;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Audit\Audited]
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'page')]
#[ORM\UniqueConstraint(name: 'uniq_page_key', columns: ['key'])]
#[ORM\UniqueConstraint(name: 'uniq_page_help_key', columns: ['help_key'])]
#[ORM\Index(name: 'idx_page_parent_sort', columns: ['parent_id', 'sort_order'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['key'], message: 'page.validation.key_unique')]
#[UniqueEntity(fields: ['helpKey'], message: 'page.validation.help_key_unique')]
class Page implements \Stringable
{
    public const string VISIBILITY_PUBLIC = 'public';
    public const string VISIBILITY_AUTHENTICATED = 'authenticated';
    public const int MAX_HELP_KEY_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['sortOrder' => \SortDirection::Ascending, 'id' => \SortDirection::Ascending])]
    private Collection $children;

    /** @var Collection<int, PageTranslation> */
    #[ORM\OneToMany(targetEntity: PageTranslation::class, mappedBy: 'page', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['locale' => \SortDirection::Ascending])]
    private Collection $translations;

    #[ORM\Column(nullable: true, enumType: PageKey::class)]
    private ?PageKey $key = null;

    /** Help articles (#596) live below the help start page and are left out of the general page navigation. */
    #[ORM\Column(length: 16, enumType: PageType::class, options: ['default' => 'page'])]
    private PageType $type = PageType::Page;

    /**
     * Stable name of a help article for links from the application (`help_link('import.rejects')`), independent of
     * title and path.
     */
    #[Assert\Length(max: self::MAX_HELP_KEY_LENGTH)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', message: 'page.validation.help_key_format')]
    #[ORM\Column(length: self::MAX_HELP_KEY_LENGTH, nullable: true)]
    private ?string $helpKey = null;

    #[Assert\Choice(choices: [self::VISIBILITY_PUBLIC, self::VISIBILITY_AUTHENTICATED])]
    #[ORM\Column(length: 32)]
    private string $visibility = self::VISIBILITY_PUBLIC;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->createdAt = Clock::get()->now();
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

    public function getKey(): ?PageKey
    {
        return $this->key;
    }

    public function setKey(?PageKey $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getType(): PageType
    {
        return $this->type;
    }

    public function setType(PageType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function isHelpArticle(): bool
    {
        return PageType::Help === $this->type;
    }

    /** The help start page or a help article: shown with the help sidebar and searched by the help search. */
    public function isInHelp(): bool
    {
        return $this->isHelpArticle() || PageKey::Help === $this->key;
    }

    public function getHelpKey(): ?string
    {
        return $this->helpKey;
    }

    public function setHelpKey(?string $helpKey): self
    {
        $helpKey = null === $helpKey ? null : trim($helpKey);
        $this->helpKey = '' === $helpKey ? null : $helpKey;

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

    public function hasPublishedTranslation(): bool
    {
        foreach ($this->translations as $translation) {
            if ($translation->isPublished()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Help articles sit below the help start page or another help article, and every page there is a help article,
     * so the help section is one tree. Only help articles have a help key.
     */
    #[Assert\Callback]
    public function validateHelpSection(ExecutionContextInterface $context): void
    {
        $parentInHelp = $this->parent instanceof self && $this->parent->isInHelp();

        if ($this->isHelpArticle() && !$parentInHelp) {
            $context->buildViolation('page.validation.help_parent_required')->atPath('parent')->addViolation();
        } elseif (!$this->isHelpArticle() && $parentInHelp) {
            $context->buildViolation('page.validation.help_child_type')->atPath('type')->addViolation();
        }

        if (null !== $this->helpKey && !$this->isHelpArticle()) {
            $context->buildViolation('page.validation.help_key_help_only')->atPath('helpKey')->addViolation();
        }
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
        foreach ($this->translations as $translation) {
            $title = $translation->getTitle();
            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        return 'Untitled page';
    }
}
