<?php

declare(strict_types=1);

namespace App\Notification\Domain\Entity;

use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Duplicate protection per recipient and key, kept apart from the inbox entry (ADR 016).
 * Rows are claimed by the publisher via DBAL.
 *
 * @psalm-suppress ClassMustBeFinal
 * @psalm-suppress UnusedClass Doctrine-mapped; written via DBAL by the publisher.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'notification_deduplication')]
#[ORM\UniqueConstraint(name: 'uniq_notification_deduplication_recipient_key', columns: ['recipient_id', 'deduplication_key'])]
#[ORM\Index(name: 'idx_notification_deduplication_expires_at', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_notification_deduplication_notification', columns: ['notification_id'])]
class NotificationDeduplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 191)]
    private string $deduplicationKey;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Notification $notification = null;

    public function __construct(
        User $recipient,
        string $deduplicationKey,
        \DateTimeImmutable $expiresAt,
        ?Notification $notification = null,
    ) {
        $this->recipient = $recipient;
        $this->deduplicationKey = $deduplicationKey;
        $this->expiresAt = $expiresAt;
        $this->notification = $notification;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getDeduplicationKey(): string
    {
        return $this->deduplicationKey;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getNotification(): ?Notification
    {
        return $this->notification;
    }
}
