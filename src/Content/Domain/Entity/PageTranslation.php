<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\Content\Infrastructure\Repository\PageTranslationRepository;
use App\Shared\Application\Locale\SupportedLocales;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
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
        $this->createdAt = new \DateTimeImmutable('now');
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
