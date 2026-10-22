<?php

declare(strict_types=1);

namespace App\Notification\Application\Definition;

/**
 * All notification types of the application, keyed by type identifier.
 * The type map is built and validated at container compile time.
 */
final class NotificationRegistry
{
    /** @var array<string, NotificationDefinition> */
    private array $definitions = [];

    /**
     * @param array<string, class-string<AppNotification>> $types
     *
     * @psalm-suppress PossiblyUnusedMethod Wired by the container; NotificationDefinitionPass sets the type map.
     */
    public function __construct(
        private readonly array $types = [],
    ) {
    }

    public function get(string $type): NotificationDefinition
    {
        if (isset($this->definitions[$type])) {
            return $this->definitions[$type];
        }

        $class = $this->types[$type] ?? throw UnknownNotificationTypeException::forType($type);
        $definition = self::readDefinition($class) ?? throw UnknownNotificationTypeException::forType($type);

        return $this->definitions[$type] = $definition;
    }

    /**
     * @param class-string $class
     */
    public static function readDefinition(string $class): ?NotificationDefinition
    {
        $attributes = new \ReflectionClass($class)->getAttributes(NotificationDefinition::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
