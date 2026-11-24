<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\Content\Domain\PageContentText;
use App\Content\Infrastructure\Repository\PageTranslationRepository;
use App\Shared\Application\Locale\SupportedLocales;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Audit\Audited]
#[ORM\Entity(repositoryClass: PageTranslationRepository::class)]
#[ORM\Table(name: 'page_translation')]
#[ORM\UniqueConstraint(name: 'uniq_page_translation_page_locale', columns: ['page_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_page_translation_path', columns: ['path'])]
#[ORM\Index(name: 'idx_page_translation_locale_status', columns: ['locale', 'status'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['page', 'locale'], message: 'page_translation.validation.locale_unique')]
#[UniqueEntity(fields: ['path'], message: 'page_translation.validation.path_unique')]
class PageTranslation implements \Stringable
{
    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_PUBLISHED = 'published';
    public const int MAX_SUMMARY_LENGTH = 300;

    /** Words per minute for the estimated reading time; a common value for reading on screen. */
    private const int READING_WORDS_PER_MINUTE = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: SupportedLocales::ALL)]
    #[Assert\Length(max: 8)]
    #[ORM\Column(length: 8)]
    private ?string $locale = null;

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

    #[Assert\Choice(choices: [self::STATUS_DRAFT, self::STATUS_PUBLISHED])]
    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(options: ['default' => false])]
    private bool $showToc = false;

    /** One sentence about the page (#1578): shown on subpage cards and help topic cards, in the help search and as meta description. */
    #[Assert\Length(max: self::MAX_SUMMARY_LENGTH)]
    #[ORM\Column(length: self::MAX_SUMMARY_LENGTH, nullable: true)]
    private ?string $summary = null;

    /**
     * @var list<array{
     *   type: string,
     *   data: array<string, mixed>,
     *   enabled?: bool
     * }>
     */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON)]
    private array $content = [];

    /**
     * Work in progress of the block editor (ADR 036): saved automatically, never shown publicly, may be incomplete.
     * Null when the editor holds no unsaved changes.
     *
     * @var list<array{type: string, data: array<string, mixed>, enabled?: bool}>|null
     */
    #[Audit\AuditIgnore]
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON, nullable: true)]
    private ?array $draftContent = null;

    /** Plain text of the saved content for the help search (#596); drafts are not searched. */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, options: ['default' => ''])]
    private string $searchText = '';

    /** Increases with every autosave, explicit save and discarded draft; requests based on an older one are refused. */
    #[Audit\AuditIgnore]
    #[ORM\Column(options: ['default' => 0])]
    private int $draftRevision = 0;

    #[Audit\AuditIgnore]
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $draftUpdatedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

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

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug ?? '';

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

    public function isShowToc(): bool
    {
        return $this->showToc;
    }

    public function setShowToc(bool $showToc): self
    {
        $this->showToc = $showToc;

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
        $this->searchText = PageContentText::extract($content);

        return $this;
    }

    /**
     * Content the editor works on: the draft when there is one, otherwise the saved content.
     *
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>
     */
    public function getEditorContent(): array
    {
        return $this->draftContent ?? $this->content;
    }

    /**
     * Explicit save from the editor. The draft is cleared by markSaved() once the content has been validated.
     *
     * @param list<array{type: string, data: array<string, mixed>, enabled?: bool}> $content
     */
    public function setEditorContent(array $content): self
    {
        $this->content = $content;
        $this->searchText = PageContentText::extract($content);

        return $this;
    }

    public function getSearchText(): string
    {
        return $this->searchText;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): self
    {
        $summary = null === $summary ? '' : trim((string) preg_replace('/\s+/u', ' ', $summary));
        $this->summary = '' === $summary ? null : $summary;

        return $this;
    }

    /**
     * Estimated reading time of the saved content in whole minutes, at least 1.
     */
    public function getReadingMinutes(): int
    {
        $words = '' === $this->searchText ? 0 : \count(preg_split('/\s+/u', $this->searchText) ?: []);

        return max(1, (int) ceil($words / self::READING_WORDS_PER_MINUTE));
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>|null
     */
    public function getDraftContent(): ?array
    {
        return $this->draftContent;
    }

    public function hasDraft(): bool
    {
        return null !== $this->draftContent;
    }

    public function getDraftRevision(): int
    {
        return $this->draftRevision;
    }

    public function getDraftUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->draftUpdatedAt;
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>, enabled?: bool}> $content
     */
    public function saveDraft(array $content): void
    {
        $this->draftContent = $content;
        $this->draftUpdatedAt = Clock::get()->now();
        ++$this->draftRevision;
    }

    public function discardDraft(): void
    {
        $this->draftContent = null;
        $this->draftUpdatedAt = null;
        ++$this->draftRevision;
    }

    /** The editor content was saved explicitly into the regular version: the draft is done. */
    public function markSaved(): void
    {
        $this->discardDraft();
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

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    #[Assert\Callback]
    public function validateParentTranslation(ExecutionContextInterface $context): void
    {
        if (!$this->page instanceof Page || null === $this->locale || '' === $this->locale) {
            return;
        }

        if (self::STATUS_PUBLISHED !== $this->status) {
            return;
        }

        $parent = $this->page->getParent();
        if (!$parent instanceof Page) {
            return;
        }

        $parentTranslation = $parent->translation($this->locale);
        if (!$parentTranslation instanceof self) {
            $context->buildViolation('page_translation.validation.parent_translation_required')
                ->atPath('locale')
                ->addViolation();
        }
    }

    #[\Override]
    public function __toString(): string
    {
        $title = $this->title ?? 'Untitled translation';
        $locale = $this->locale ?? '?';

        return sprintf('%s [%s]', $title, $locale);
    }
}
