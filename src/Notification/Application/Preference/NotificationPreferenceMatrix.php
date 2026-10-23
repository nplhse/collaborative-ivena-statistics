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

    /**
     * Turns the submitted checkboxes into override changes (ADR 016, "Preferences and resolution").
     *
     * - An unchanged checkbox keeps its stored override, so untouched types keep following their category.
     * - A changed category checkbox stores an override, or removes it when it now equals the common
     *   default of its types.
     * - A changed type checkbox stores an override, or removes it when it now equals what the type
     *   inherits after this submission (the new category override, else the definition default).
     *
     * Only controls of this matrix are considered; missing fields count as unchecked.
     *
     * @param array<string, bool> $checked keyed by {@see NotificationPreferenceControl::fieldName()}
     *
     * @return list<NotificationPreferenceChange> changes that differ from the stored state
     */
    public function changesFor(array $checked): array
    {
        $changes = [];
        foreach ($this->categories as $category) {
            /** @var array<string, bool|null> $categoryOverrides new category override per channel */
            $categoryOverrides = [];
            foreach ($category->controls as $channel => $control) {
                if (!$control->isConfigurable()) {
                    continue;
                }

                $field = $control->fieldName();
                $override = $this->newOverride($control, $checked[$field] ?? false, $control->default);
                $categoryOverrides[$channel] = $override;
                $this->collect($changes, $control, $override);
            }

            foreach ($category->types as $type) {
                foreach ($type->controls as $channel => $control) {
                    if (!$control->isConfigurable()) {
                        continue;
                    }

                    $field = $control->fieldName();
                    $inherited = $categoryOverrides[$channel] ?? $control->default;
                    $this->collect($changes, $control, $this->newOverride($control, $checked[$field] ?? false, $inherited));
                }
            }
        }

        return $changes;
    }

    private function newOverride(NotificationPreferenceControl $control, bool $checked, ?bool $inherited): ?bool
    {
        if ($checked === $control->isChecked()) {
            return $control->override;
        }

        return $checked === $inherited ? null : $checked;
    }

    /**
     * @param list<NotificationPreferenceChange> $changes
     */
    private function collect(array &$changes, NotificationPreferenceControl $control, ?bool $override): void
    {
        if ($override !== $control->override) {
            $changes[] = new NotificationPreferenceChange($control->scope, $control->key, $control->channel, $override);
        }
    }
}
