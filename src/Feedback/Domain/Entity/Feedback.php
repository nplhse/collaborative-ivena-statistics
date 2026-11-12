<?php

declare(strict_types=1);

namespace App\Feedback\Domain\Entity;

use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Domain\Enum\FeedbackClassification;
use App\Feedback\Domain\Enum\FeedbackStatus;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\Table(name: 'feedback')]
#[ORM\Index(name: 'idx_feedback_classification_created', columns: ['classification', 'created_at'])]
#[ORM\Index(name: 'idx_feedback_message_fingerprint', columns: ['message_fingerprint'])]
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

    #[ORM\Column(enumType: FeedbackClassification::class, options: ['default' => 'normal'])]
    private FeedbackClassification $classification = FeedbackClassification::NORMAL;

    /**
     * Reason codes of the current quarantine (e.g. `contains_url`, `manual`); null while the entry is normal.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON, nullable: true)]
    private ?array $classificationReasons = null;

    /** Spam score of an automatic quarantine; null for normal entries and manual quarantines. */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT, nullable: true)]
    private ?int $spamScore = null;

    /** When the classification last changed (quarantined or released); null if it never changed. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $classifiedAt = null;

    /** Reviewer who last changed the classification; null for the automatic classification. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $classifiedBy = null;

    /** When the quarantined entry was included in (or excluded from) the weekly quarantine summary. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $quarantineReportedAt = null;

    /** SHA-256 of the normalised message, used to spot repeated messages; null when nothing is left. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $messageFingerprint = null;

    public function __construct()
    {
        $this->createdAt = Clock::get()->now();
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

    public function getMessageFingerprint(): ?string
    {
        return $this->messageFingerprint;
    }

    public function setMessageFingerprint(?string $messageFingerprint): static
    {
        $this->messageFingerprint = $messageFingerprint;

        return $this;
    }

    public function getClassification(): FeedbackClassification
    {
        return $this->classification;
    }

    public function isQuarantined(): bool
    {
        return FeedbackClassification::QUARANTINED === $this->classification;
    }

    /** @return list<string> */
    public function getClassificationReasons(): array
    {
        return $this->classificationReasons ?? [];
    }

    public function getSpamScore(): ?int
    {
        return $this->spamScore;
    }

    public function getClassifiedAt(): ?\DateTimeImmutable
    {
        return $this->classifiedAt;
    }

    public function getClassifiedBy(): ?User
    {
        return $this->classifiedBy;
    }

    public function getQuarantineReportedAt(): ?\DateTimeImmutable
    {
        return $this->quarantineReportedAt;
    }

    /**
     * Moves the entry into quarantine. Without a reviewer the quarantine is automatic and waits for the
     * weekly summary; a reviewer's quarantine is known already and is not reported again.
     *
     * @param non-empty-list<string> $reasons
     */
    public function quarantine(array $reasons, ?int $spamScore, ?User $reviewer, \DateTimeImmutable $at): static
    {
        if ($this->isQuarantined()) {
            throw new \LogicException(\sprintf('Feedback %s is already quarantined.', $this));
        }

        $this->classification = FeedbackClassification::QUARANTINED;
        $this->classificationReasons = array_values(array_unique($reasons));
        $this->spamScore = $spamScore;
        $this->classifiedAt = $at;
        $this->classifiedBy = $reviewer;
        $this->quarantineReportedAt = $reviewer instanceof User ? $at : null;

        return $this;
    }

    /** Releases the entry from quarantine; reasons and score are dropped, the workflow status stays. */
    public function release(User $reviewer, \DateTimeImmutable $at): static
    {
        if (!$this->isQuarantined()) {
            throw new \LogicException(\sprintf('Feedback %s is not quarantined.', $this));
        }

        $this->classification = FeedbackClassification::NORMAL;
        $this->classificationReasons = null;
        $this->spamScore = null;
        $this->classifiedAt = $at;
        $this->classifiedBy = $reviewer;

        return $this;
    }

    #[\Override]
    public function __toString(): string
    {
        return null !== $this->id ? \sprintf('#%d', $this->id) : 'feedback';
    }
}
