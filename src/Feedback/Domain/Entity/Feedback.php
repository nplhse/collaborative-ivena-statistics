<?php

declare(strict_types=1);

namespace App\Feedback\Domain\Entity;

use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Domain\Enum\FeedbackStatus;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\Table(name: 'feedback')]
class Feedback implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: FeedbackCategory::class)]
    private ?FeedbackCategory $category = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT)]
    private ?string $message = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $guestEmail = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy = null;

    #[ORM\Column(length: 2048)]
    private ?string $pageUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $routeName = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON, nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column(enumType: FeedbackStatus::class, options: ['default' => 'new'])]
    private FeedbackStatus $status = FeedbackStatus::NEW;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?FeedbackCategory
    {
        return $this->category;
    }

    public function setCategory(FeedbackCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getGuestEmail(): ?string
    {
        return $this->guestEmail;
    }

    public function setGuestEmail(?string $guestEmail): static
    {
        $this->guestEmail = '' === ($guestEmail ?? '') ? null : $guestEmail;

        return $this;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(?User $submittedBy): static
    {
        $this->submittedBy = $submittedBy;

        return $this;
    }

    public function getPageUrl(): ?string
    {
        return $this->pageUrl;
    }

    /** Nur der URL-Pfad (für Anzeige in Mails/Admin), ohne Query. */
    public function getPagePath(): string
    {
        if (null === $this->pageUrl || '' === $this->pageUrl) {
            return '/';
        }

        $path = parse_url($this->pageUrl, \PHP_URL_PATH);
        if (!\is_string($path) || '' === $path) {
            return '/';
        }

        return $path;
    }

    public function setPageUrl(string $pageUrl): static
    {
        $this->pageUrl = $pageUrl;

        return $this;
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function setRouteName(?string $routeName): static
    {
        $this->routeName = $routeName;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getContext(): ?array
    {
        return $this->context;
    }

    /** @param array<string, mixed>|null $context */
    public function setContext(?array $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    public function setAppVersion(?string $appVersion): static
    {
        $this->appVersion = $appVersion;

        return $this;
    }

    public function getStatus(): FeedbackStatus
    {
        return $this->status;
    }

    public function setStatus(FeedbackStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[\Override]
    public function __toString(): string
    {
        return null !== $this->id ? \sprintf('#%d', $this->id) : 'feedback';
    }
}
