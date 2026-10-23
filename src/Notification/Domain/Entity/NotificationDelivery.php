<?php

declare(strict_types=1);

namespace App\Notification\Domain\Entity;

use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use App\Notification\Domain\Enum\NotificationDeliveryStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One external delivery of a notification, e.g. its email (ADR 016). Rows are written via DBAL by the
 * publisher and the delivery handler; the entity only maps the table.
 *
 * @psalm-suppress ClassMustBeFinal
 * @psalm-suppress UnusedClass Doctrine-mapped; written via DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'notification_delivery')]
#[ORM\UniqueConstraint(name: 'uniq_notification_delivery_channel', columns: ['notification_id', 'channel'])]
#[ORM\Index(name: 'idx_notification_delivery_notification', columns: ['notification_id'])]
#[ORM\Index(name: 'idx_notification_delivery_pending', columns: ['created_at'], options: ['where' => "(status = 'pending')"])]
class NotificationDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Notification $notification;

    #[ORM\Column(length: 32, enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    #[ORM\Column(length: 16, enumType: NotificationDeliveryStatus::class)]
    private NotificationDeliveryStatus $status = NotificationDeliveryStatus::Pending;

    #[ORM\Column]
    private int $attemptCount = 0;

    #[ORM\Column(length: 64, nullable: true, enumType: NotificationDeliveryFailureReason::class)]
    private ?NotificationDeliveryFailureReason $reasonCode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $statusChangedAt;

    public function __construct(Notification $notification, NotificationChannel $channel, \DateTimeImmutable $createdAt)
    {
        $this->notification = $notification;
        $this->channel = $channel;
        $this->createdAt = $createdAt;
        $this->statusChangedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getStatus(): NotificationDeliveryStatus
    {
        return $this->status;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getReasonCode(): ?NotificationDeliveryFailureReason
    {
        return $this->reasonCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatusChangedAt(): \DateTimeImmutable
    {
        return $this->statusChangedAt;
    }
}
