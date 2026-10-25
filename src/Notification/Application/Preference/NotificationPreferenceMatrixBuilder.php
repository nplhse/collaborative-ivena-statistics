<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Builds the settings page model from the registry and the user's overrides (ADR 016,
 * "Preferences and resolution"). Role-scoped types are offered only to users with the required role.
 */
final readonly class NotificationPreferenceMatrixBuilder
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NotificationRegistry $registry,
        private RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    public function build(User $user, NotificationPreferenceSet $preferences): NotificationPreferenceMatrix
    {
        $roles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        /** @var array<string, list<NotificationDefinition>> $byCategory */
        $byCategory = [];
        $channelsInUse = [];
        foreach ($this->registry->definitions() as $definition) {
            if (null !== $definition->requiredRole && !\in_array($definition->requiredRole, $roles, true)) {
                continue;
            }

            $byCategory[$definition->category->value][] = $definition;
            foreach ($definition->availableChannels as $channel) {
                $channelsInUse[$channel->value] = true;
            }
        }

        $channels = array_values(array_filter(
            NotificationChannel::cases(),
            static fn (NotificationChannel $channel): bool => isset($channelsInUse[$channel->value]),
        ));

        $categories = [];
        foreach (NotificationCategory::cases() as $category) {
            $definitions = $byCategory[$category->value] ?? [];
            if ([] === $definitions) {
                continue;
            }

            $categories[] = $this->buildCategory($category, $definitions, $channels, $preferences);
        }

        return new NotificationPreferenceMatrix($channels, $categories);
    }

    /**
     * @param non-empty-list<NotificationDefinition> $definitions
     * @param list<NotificationChannel>              $channels
     */
    private function buildCategory(
        NotificationCategory $category,
        array $definitions,
        array $channels,
        NotificationPreferenceSet $preferences,
    ): NotificationCategoryPreferences {
        $types = [];
        foreach ($definitions as $definition) {
            $typeControls = [];
            foreach ($channels as $channel) {
                $typeControls[$channel->value] = $this->typeControl($definition, $channel, $preferences);
            }

            $types[] = new NotificationTypePreferences($definition, $typeControls);
        }

        $categoryControls = [];
        foreach ($channels as $channel) {
            $states = [];
            foreach ($types as $type) {
                $control = $type->controls[$channel->value];
                if ($control->isConfigurable()) {
                    $states[] = $control->isChecked();
                }
            }

            $categoryControls[$channel->value] = $this->categoryControl($category, $definitions, $channel, $preferences, \count(array_unique($states)) > 1);
        }

        return new NotificationCategoryPreferences($category, $categoryControls, $types);
    }

    /**
     * A category control covers the configurable types of the category that offer the channel
     * and do not make it mandatory.
     *
     * @param non-empty-list<NotificationDefinition> $definitions
     */
    private function categoryControl(
        NotificationCategory $category,
        array $definitions,
        NotificationChannel $channel,
        NotificationPreferenceSet $preferences,
        bool $typesDiffer,
    ): NotificationPreferenceControl {
        $defaults = [];
        foreach ($definitions as $definition) {
            if ($this->isConfigurableChannel($definition, $channel)) {
                $defaults[] = \in_array($channel, $definition->defaultChannels, true);
            }
        }

        if ([] === $defaults) {
            return new NotificationPreferenceControl(NotificationPreferenceScope::Category, $category->value, $channel, NotificationPreferenceControlKind::Unavailable);
        }

        $uniqueDefaults = array_values(array_unique($defaults));
        $commonDefault = 1 === \count($uniqueDefaults) ? $uniqueDefaults[0] : null;

        return new NotificationPreferenceControl(
            NotificationPreferenceScope::Category,
            $category->value,
            $channel,
            NotificationPreferenceControlKind::Configurable,
            $preferences->get(NotificationPreferenceScope::Category, $category->value, $channel),
            $commonDefault,
            $commonDefault,
            $typesDiffer,
        );
    }

    private function typeControl(
        NotificationDefinition $definition,
        NotificationChannel $channel,
        NotificationPreferenceSet $preferences,
    ): NotificationPreferenceControl {
        $default = \in_array($channel, $definition->defaultChannels, true);
        $kind = match (true) {
            !\in_array($channel, $definition->availableChannels, true) => NotificationPreferenceControlKind::Unavailable,
            \in_array($channel, $definition->mandatoryChannels, true) => NotificationPreferenceControlKind::Mandatory,
            !$definition->configurable => NotificationPreferenceControlKind::Fixed,
            default => NotificationPreferenceControlKind::Configurable,
        };

        if (NotificationPreferenceControlKind::Configurable !== $kind) {
            return new NotificationPreferenceControl(NotificationPreferenceScope::Type, $definition->type, $channel, $kind, null, $default, $default);
        }

        return new NotificationPreferenceControl(
            NotificationPreferenceScope::Type,
            $definition->type,
            $channel,
            $kind,
            $preferences->get(NotificationPreferenceScope::Type, $definition->type, $channel),
            $preferences->get(NotificationPreferenceScope::Category, $definition->category->value, $channel) ?? $default,
            $default,
        );
    }

    private function isConfigurableChannel(NotificationDefinition $definition, NotificationChannel $channel): bool
    {
        return $definition->configurable
            && \in_array($channel, $definition->availableChannels, true)
            && !\in_array($channel, $definition->mandatoryChannels, true);
    }
}
