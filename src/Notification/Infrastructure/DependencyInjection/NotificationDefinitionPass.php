<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\DependencyInjection;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Definition\NotificationDefinitionValidator;
use App\Notification\Application\Definition\NotificationRegistry;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * Collects all classes carrying {@see NotificationDefinition} into the {@see NotificationRegistry}
 * and fails the container build on invalid definitions (ADR 016).
 *
 * Notification classes are messages, not services: their service definitions only serve
 * discovery and are removed here.
 */
final class NotificationDefinitionPass implements CompilerPassInterface
{
    public const string TAG = 'app.notification.definition';

    public static function register(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            NotificationDefinition::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(self::TAG);
            },
        );

        $container->addCompilerPass(new self());
    }

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $validator = new NotificationDefinitionValidator();
        $types = [];

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $serviceId) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($serviceId)->getClass() ?? $serviceId);
            if (!\is_string($class) || !class_exists($class)) {
                throw new LogicException(sprintf('Notification definition service "%s" has no loadable class.', $serviceId));
            }

            if (!is_subclass_of($class, AppNotification::class)) {
                throw new LogicException(sprintf('Notification type class "%s" must extend %s.', $class, AppNotification::class));
            }

            $definition = NotificationRegistry::readDefinition($class);
            if (!$definition instanceof NotificationDefinition) {
                throw new LogicException(sprintf('Notification type class "%s" has no #[NotificationDefinition] attribute.', $class));
            }

            $errors = $validator->validate($definition);
            if ([] !== $errors) {
                throw new LogicException(sprintf('Invalid notification definition on "%s": %s.', $class, implode('; ', $errors)));
            }

            if (isset($types[$definition->type])) {
                throw new LogicException(sprintf('Notification type "%s" is declared by both "%s" and "%s".', $definition->type, $types[$definition->type], $class));
            }

            $types[$definition->type] = $class;
            $container->removeDefinition($serviceId);
        }

        ksort($types);

        if ($container->hasDefinition(NotificationRegistry::class)) {
            $container->getDefinition(NotificationRegistry::class)->setArgument('$types', $types);
        }
    }
}
