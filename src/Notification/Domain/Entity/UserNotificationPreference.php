<?php

declare(strict_types=1);

namespace App\Notification\Domain\Entity;

use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * One preference override of a user for a category or type and one channel (ADR 016,
 * "Preferences and resolution"). Only overrides are stored; a missing row means "use the default".
 * Rows are written via DBAL by the preference store.
 *
 * @psalm-suppress ClassMustBeFinal
 * @psalm-suppress UnusedClass Doctrine-mapped; written and read via DBAL by the preference store.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'user_notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_user_notification_preference', columns: ['user_id', 'scope', 'preference_key', 'channel'])]
class UserNotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: NotificationPreferenceScope::class)]
    private NotificationPreferenceScope $scope;

    /** Category value or type identifier, depending on the scope. */
    #[ORM\Column(length: 100)]
    private string $preferenceKey;

    #[ORM\Column(length: 16, enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    #[ORM\Column]
    private bool $enabled;

    public function __construct(
        User $user,
        NotificationPreferenceScope $scope,
        string $preferenceKey,
        NotificationChannel $channel,
        bool $enabled,
    ) {
        $this->user = $user;
        $this->scope = $scope;
        $this->preferenceKey = $preferenceKey;
        $this->channel = $channel;
        $this->enabled = $enabled;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getScope(): NotificationPreferenceScope
    {
        return $this->scope;
    }

    public function getPreferenceKey(): string
    {
        return $this->preferenceKey;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
