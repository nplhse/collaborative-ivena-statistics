<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;

/**
 * All stored overrides of one user.
 */
final readonly class NotificationPreferenceSet
{
    /**
     * @param array<string, bool> $overrides keyed by {@see self::index()}
     */
    private function __construct(
        private array $overrides,
    ) {
    }

    /**
     * @param iterable<NotificationPreferenceChange> $overrides changes with enabled = null are skipped
     */
    public static function fromOverrides(iterable $overrides): self
    {
        $map = [];
        foreach ($overrides as $override) {
            if (null !== $override->enabled) {
                $map[self::index($override->scope, $override->key, $override->channel)] = $override->enabled;
            }
        }

        return new self($map);
    }

    public function get(NotificationPreferenceScope $scope, string $key, NotificationChannel $channel): ?bool
    {
        return $this->overrides[self::index($scope, $key, $channel)] ?? null;
    }

    public function forDefinition(NotificationDefinition $definition): NotificationPreferenceOverrides
    {
        if (!$definition->configurable) {
            return NotificationPreferenceOverrides::none();
        }

        $typeOverrides = [];
        $categoryOverrides = [];
        foreach (NotificationChannel::cases() as $channel) {
            $type = $this->get(NotificationPreferenceScope::Type, $definition->type, $channel);
            if (null !== $type) {
                $typeOverrides[$channel->value] = $type;
            }

            $category = $this->get(NotificationPreferenceScope::Category, $definition->category->value, $channel);
            if (null !== $category) {
                $categoryOverrides[$channel->value] = $category;
            }
        }

        return new NotificationPreferenceOverrides($typeOverrides, $categoryOverrides);
    }

    private static function index(NotificationPreferenceScope $scope, string $key, NotificationChannel $channel): string
    {
        return $scope->value.'|'.$key.'|'.$channel->value;
    }
}
