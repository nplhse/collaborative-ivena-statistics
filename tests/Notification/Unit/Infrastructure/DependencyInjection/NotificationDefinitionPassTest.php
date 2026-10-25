<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Infrastructure\DependencyInjection;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Infrastructure\DependencyInjection\NotificationDefinitionPass;
use App\Tests\Support\Notification\Invalid\DuplicateTypeTestNotification;
use App\Tests\Support\Notification\Invalid\MissingAttributeTestNotification;
use App\Tests\Support\Notification\Invalid\NotExtendingAppNotification;
use App\Tests\Support\Notification\Invalid\ToastWithoutInAppTestNotification;
use App\Tests\Support\Notification\Type\InAppTestNotification;
use App\Tests\Support\Notification\Type\ToastTestNotification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;

final class NotificationDefinitionPassTest extends TestCase
{
    public function testCollectsTypesIntoRegistryAndRemovesDiscoveryServices(): void
    {
        $container = $this->container(ToastTestNotification::class, InAppTestNotification::class);

        new NotificationDefinitionPass()->process($container);

        self::assertSame(
            ['test.in_app' => InAppTestNotification::class, 'test.toast' => ToastTestNotification::class],
            $container->getDefinition(NotificationRegistry::class)->getArgument('$types'),
        );
        self::assertFalse($container->hasDefinition(InAppTestNotification::class));
        self::assertFalse($container->hasDefinition(ToastTestNotification::class));
    }

    public function testAutoconfigurationTagsClassesWithTheAttribute(): void
    {
        $container = new ContainerBuilder();
        NotificationDefinitionPass::register($container);

        $configurators = $container->getAttributeAutoconfigurators()[NotificationDefinition::class] ?? [];
        self::assertCount(1, $configurators);

        $definition = new ChildDefinition('');
        $configurators[0]($definition);

        self::assertTrue($definition->hasTag(NotificationDefinitionPass::TAG));
    }

    public function testRejectsDuplicateType(): void
    {
        $container = $this->container(InAppTestNotification::class, DuplicateTypeTestNotification::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Notification type "test.in_app" is declared by both');

        new NotificationDefinitionPass()->process($container);
    }

    public function testRejectsInvalidDefinition(): void
    {
        $container = $this->container(ToastWithoutInAppTestNotification::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('toast is available without in_app');

        new NotificationDefinitionPass()->process($container);
    }

    public function testRejectsClassNotExtendingAppNotification(): void
    {
        $container = $this->container(NotExtendingAppNotification::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must extend');

        new NotificationDefinitionPass()->process($container);
    }

    public function testRejectsServiceWithoutLoadableClass(): void
    {
        $container = $this->container();
        $container->setDefinition('app.missing_notification', new Definition('App\Tests\DoesNotExist'))->addTag(NotificationDefinitionPass::TAG);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no loadable class');

        new NotificationDefinitionPass()->process($container);
    }

    public function testRejectsClassWithoutAttribute(): void
    {
        $container = $this->container(MissingAttributeTestNotification::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no #[NotificationDefinition] attribute');

        new NotificationDefinitionPass()->process($container);
    }

    /**
     * @param class-string ...$classes
     */
    private function container(string ...$classes): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(NotificationRegistry::class, new Definition(NotificationRegistry::class));

        foreach ($classes as $class) {
            $container->setDefinition($class, new Definition($class))->addTag(NotificationDefinitionPass::TAG);
        }

        return $container;
    }
}
