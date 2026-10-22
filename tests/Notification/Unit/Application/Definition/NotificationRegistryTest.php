<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Definition;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Definition\UnknownNotificationTypeException;
use App\Tests\Support\Notification\Invalid\MissingAttributeTestNotification;
use App\Tests\Support\Notification\Type\InAppTestNotification;
use PHPUnit\Framework\TestCase;

final class NotificationRegistryTest extends TestCase
{
    public function testReturnsAndCachesDefinitions(): void
    {
        $registry = new NotificationRegistry(['test.in_app' => InAppTestNotification::class]);

        $definition = $registry->get('test.in_app');

        self::assertSame('test.in_app', $definition->type);
        self::assertSame($definition, $registry->get('test.in_app'));
    }

    public function testGetThrowsForUnknownType(): void
    {
        $this->expectException(UnknownNotificationTypeException::class);
        $this->expectExceptionMessage('Notification type "test.removed" is not registered.');

        new NotificationRegistry()->get('test.removed');
    }

    public function testGetThrowsWhenClassLostItsAttribute(): void
    {
        $this->expectException(UnknownNotificationTypeException::class);

        new NotificationRegistry(['test.lost' => MissingAttributeTestNotification::class])->get('test.lost');
    }
}
