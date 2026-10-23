<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Everything the settings page shows for one user: the channel columns and, per category,
 * the category controls and the controls of its types.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the settings template.
 */
final readonly class NotificationPreferenceMatrix
{
    /**
     * @param list<NotificationChannel>             $channels
     * @param list<NotificationCategoryPreferences> $categories
     */
    public function __construct(
        public array $channels,
        public array $categories,
    ) {
    }

    /**
     * @return list<NotificationPreferenceControl>
     */
    public function configurableControls(): array
    {
        $controls = [];
        foreach ($this->categories as $category) {
            array_push($controls, ...$category->configurableControls());
        }

        return $controls;
    }
}
