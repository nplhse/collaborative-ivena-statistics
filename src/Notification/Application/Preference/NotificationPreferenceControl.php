<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;

/**
 * One cell of the settings page: a category or type and one channel, shown as a checkbox.
 */
final readonly class NotificationPreferenceControl
{
    /**
     * @param bool|null $override    the stored override, null when none is stored
     * @param bool|null $inherited   what applies without an override of this level: for types the
     *                               category override or else the definition default, for categories
     *                               the default of all their types; null when those defaults differ
     * @param bool|null $default     the definition default (types) or the common default of the
     *                               types (categories); null when those defaults differ
     * @param bool      $typesDiffer categories only: the configurable types of this channel are
     *                               currently not all on or all off
     */
    public function __construct(
        public NotificationPreferenceScope $scope,
        public string $key,
        public NotificationChannel $channel,
        public NotificationPreferenceControlKind $kind,
        public ?bool $override = null,
        public ?bool $inherited = null,
        public ?bool $default = null,
        public bool $typesDiffer = false,
    ) {
    }

    public function isConfigurable(): bool
    {
        return NotificationPreferenceControlKind::Configurable === $this->kind;
    }

    /**
     * Whether the checkbox is shown checked: the channel is used as stored now. A category whose
     * types have different defaults and no override is shown unchecked (and marked as mixed).
     */
    public function isChecked(): bool
    {
        return match ($this->kind) {
            NotificationPreferenceControlKind::Mandatory => true,
            NotificationPreferenceControlKind::Unavailable => false,
            default => $this->override ?? $this->inherited ?? false,
        };
    }

    /**
     * A category whose types are currently set differently, shown as a partly ticked checkbox.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the settings template.
     */
    public function isMixed(): bool
    {
        return $this->isConfigurable() && $this->typesDiffer;
    }

    /**
     * A stable form field name; type identifiers contain dots, which form names do not allow.
     */
    public function fieldName(): string
    {
        return $this->scope->value.'-'.str_replace('.', '-', $this->key).'-'.$this->channel->value;
    }
}
