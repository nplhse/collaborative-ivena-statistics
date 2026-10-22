<?php

declare(strict_types=1);

namespace App\Notification\Domain\Entity;

use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationPriority;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One notification for one recipient (ADR 016). The publisher writes rows via DBAL
 * (see DbalNotificationPublisher); the entity is used for reading.
 *
 * @psalm-suppress ClassMustBeFinal
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_inbox', columns: ['recipient_id', 'created_at', 'id'])]
#[ORM\Index(
    name: 'idx_notification_unread',
    columns: ['recipient_id'],
    options: ['where' => '((read_at IS NULL) AND (removed_at IS NULL) AND (in_app_visible = true))'],
)]
#[ORM\Index(
    name: 'idx_notification_toast_pending',
    columns: ['recipient_id', 'toast_pending_until'],
    options: ['where' => '(toast_claimed_at IS NULL)'],
)]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column(length: 32, enumType: NotificationCategory::class)]
    private NotificationCategory $category;

    #[ORM\Column(length: 16, enumType: NotificationPriority::class)]
    private NotificationPriority $priority;

    #[ORM\Column(length: 64)]
    private string $translationDomain;

    #[ORM\Column(length: 191)]
    private string $titleKey;

    #[ORM\Column(length: 191)]
    private string $messageKey;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $parameters = [];

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $actionRoute = null;

    /** @var array<string, scalar> */
    #[ORM\Column(type: Types::JSON)]
    private array $actionParameters = [];

    #[ORM\Column(length: 16, enumType: NotificationAccessScope::class)]
    private NotificationAccessScope $accessScope;

    #[ORM\Column(nullable: true)]
    private ?int $scopeReferenceId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requiredRole = null;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column]
    private bool $inAppVisible;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $toastPendingUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $toastClaimedAt = null;

    /**
     * @param array<string, scalar|null> $parameters
     * @param array<string, scalar>      $actionParameters
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        User $recipient,
        string $type,
        NotificationCategory $category,
        NotificationPriority $priority,
        string $translationDomain,
        string $titleKey,
        string $messageKey,
        NotificationAccessScope $accessScope,
        bool $inAppVisible,
        \DateTimeImmutable $createdAt,
        array $parameters = [],
        ?string $actionRoute = null,
        array $actionParameters = [],
        ?int $scopeReferenceId = null,
        array $metadata = [],
        ?\DateTimeImmutable $toastPendingUntil = null,
        ?string $requiredRole = null,
    ) {
        $this->recipient = $recipient;
        $this->type = $type;
        $this->category = $category;
        $this->priority = $priority;
        $this->translationDomain = $translationDomain;
        $this->titleKey = $titleKey;
        $this->messageKey = $messageKey;
        $this->accessScope = $accessScope;
        $this->inAppVisible = $inAppVisible;
        $this->createdAt = $createdAt;
        $this->parameters = $parameters;
        $this->actionRoute = $actionRoute;
        $this->actionParameters = $actionParameters;
        $this->scopeReferenceId = $scopeReferenceId;
        $this->metadata = $metadata;
        $this->toastPendingUntil = $toastPendingUntil;
        $this->requiredRole = $requiredRole;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCategory(): NotificationCategory
    {
        return $this->category;
    }

    public function getPriority(): NotificationPriority
    {
        return $this->priority;
    }

    public function getTranslationDomain(): string
    {
        return $this->translationDomain;
    }

    public function getTitleKey(): string
    {
        return $this->titleKey;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getActionRoute(): ?string
    {
        return $this->actionRoute;
    }

    /**
     * @return array<string, scalar>
     */
    public function getActionParameters(): array
    {
        return $this->actionParameters;
    }

    public function getAccessScope(): NotificationAccessScope
    {
        return $this->accessScope;
    }

    public function getScopeReferenceId(): ?int
    {
        return $this->scopeReferenceId;
    }

    public function getRequiredRole(): ?string
    {
        return $this->requiredRole;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isInAppVisible(): bool
    {
        return $this->inAppVisible;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getRemovedAt(): ?\DateTimeImmutable
    {
        return $this->removedAt;
    }

    public function getToastPendingUntil(): ?\DateTimeImmutable
    {
        return $this->toastPendingUntil;
    }

    public function getToastClaimedAt(): ?\DateTimeImmutable
    {
        return $this->toastClaimedAt;
    }
}
