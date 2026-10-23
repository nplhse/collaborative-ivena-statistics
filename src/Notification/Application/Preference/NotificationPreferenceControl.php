<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;

/**
 * One cell of the settings page: a category or type and one channel.
 */
final readonly class NotificationPreferenceControl
{
    /**
     * @param bool|null $override  the stored override, null when none is stored
     * @param bool|null $inherited what applies without an override: the category override or the
     *                             definition default for types, the default of all types for
     *                             categories; null when the types of a category differ
     */
    public function __construct(
        public NotificationPreferenceScope $scope,
        public string $key,
        public NotificationChannel $channel,
        public NotificationPreferenceControlKind $kind,
        public ?bool $override = null,
        public ?bool $inherited = null,
    ) {
    }

    public function isConfigurable(): bool
    {
        return NotificationPreferenceControlKind::Configurable === $this->kind;
    }

    public function state(): NotificationPreferenceState
    {
        return NotificationPreferenceState::fromOverride($this->override);
    }

    /**
     * Whether the channel is used for this control as stored now (fixed and mandatory controls included).
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the settings template.
     */
    public function isEffectivelyEnabled(): ?bool
    {
        return match ($this->kind) {
            NotificationPreferenceControlKind::Mandatory => true,
            NotificationPreferenceControlKind::Unavailable => false,
            default => $this->override ?? $this->inherited,
        };
    }

    /**
     * A stable form field name; type identifiers contain dots, which form names do not allow.
     */
    public function fieldName(): string
    {
        return $this->scope->value.'-'.str_replace('.', '-', $this->key).'-'.$this->channel->value;
    }

    public function change(NotificationPreferenceState $state): NotificationPreferenceChange
    {
        return new NotificationPreferenceChange($this->scope, $this->key, $this->channel, $state->toOverride());
    }
}
