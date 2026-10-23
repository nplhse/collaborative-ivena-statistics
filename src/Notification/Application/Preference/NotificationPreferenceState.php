<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

/**
 * The three states of a preference control on the settings page (ADR 016): a missing override
 * and an explicit "off" mean different things.
 */
enum NotificationPreferenceState: string
{
    /** No override is stored; the default applies. */
    case Default = 'default';
    case On = 'on';
    case Off = 'off';

    public static function fromOverride(?bool $enabled): self
    {
        return match ($enabled) {
            null => self::Default,
            true => self::On,
            false => self::Off,
        };
    }

    public function toOverride(): ?bool
    {
        return match ($this) {
            self::Default => null,
            self::On => true,
            self::Off => false,
        };
    }
}
