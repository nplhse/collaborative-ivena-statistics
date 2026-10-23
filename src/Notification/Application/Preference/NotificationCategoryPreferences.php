<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Domain\Enum\NotificationCategory;

/**
 * @psalm-suppress PossiblyUnusedProperty Read by the settings template.
 */
final readonly class NotificationCategoryPreferences
{
    /**
     * @param array<string, NotificationPreferenceControl> $controls keyed by channel value
     * @param list<NotificationTypePreferences>            $types
     */
    public function __construct(
        public NotificationCategory $category,
        public array $controls,
        public array $types,
    ) {
    }

    /**
     * @return list<NotificationPreferenceControl>
     */
    public function configurableControls(): array
    {
        $controls = array_values(array_filter($this->controls, static fn (NotificationPreferenceControl $control): bool => $control->isConfigurable()));
        foreach ($this->types as $type) {
            foreach ($type->controls as $control) {
                if ($control->isConfigurable()) {
                    $controls[] = $control;
                }
            }
        }

        return $controls;
    }
}
