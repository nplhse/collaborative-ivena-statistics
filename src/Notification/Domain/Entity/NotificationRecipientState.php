<?php

declare(strict_types=1);

namespace App\Notification\Domain\Entity;

use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Change version and unread counter per recipient, used for the badge and for polling (ADR 016).
 * Updated by the publisher via DBAL in the same transaction as every visible change.
 *
 * @psalm-suppress ClassMustBeFinal
 * @psalm-suppress UnusedClass Doctrine-mapped; written via DBAL by the publisher, read by the inbox UI (step 2).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'notification_recipient_state')]
class NotificationRecipientState
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $version = 0;

    #[ORM\Column]
    private int $unreadCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        \DateTimeImmutable $updatedAt,
        int $version = 0,
        int $unreadCount = 0,
    ) {
        $this->user = $user;
        $this->updatedAt = $updatedAt;
        $this->version = $version;
        $this->unreadCount = $unreadCount;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getUnreadCount(): int
    {
        return $this->unreadCount;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
