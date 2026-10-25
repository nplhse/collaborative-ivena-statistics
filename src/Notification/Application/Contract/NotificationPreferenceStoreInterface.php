<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Application\Preference\NotificationPreferenceOverrides;
use App\Notification\Application\Preference\NotificationPreferenceSet;

/**
 * Reads and writes a user's preference overrides (ADR 016, "Preferences and resolution").
 * Only overrides are stored; a missing entry means "use the default".
 */
interface NotificationPreferenceStoreInterface
{
    /**
     * The overrides that apply to one type: its own and those of its category.
     * Non-configurable types never have overrides.
     */
    public function overridesFor(int $userId, NotificationDefinition $definition): NotificationPreferenceOverrides;

    /**
     * All overrides of a user, for the settings page.
     */
    public function load(int $userId): NotificationPreferenceSet;

    /**
     * Stores or removes overrides in one transaction. A change with enabled = null removes the override.
     *
     * @param list<NotificationPreferenceChange> $changes
     */
    public function save(int $userId, array $changes): void;
}
