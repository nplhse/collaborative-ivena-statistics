<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Domain\Enum;

use App\Notification\Domain\Enum\NotificationPriority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Notifier\Notification\Notification;

final class NotificationPriorityTest extends TestCase
{
    #[DataProvider('importanceLevels')]
    public function testMapsOntoNotifierImportance(NotificationPriority $priority, string $importance): void
    {
        self::assertSame($importance, $priority->toNotifierImportance());
    }

    /**
     * @return iterable<string, array{NotificationPriority, string}>
     */
    public static function importanceLevels(): iterable
    {
        yield 'low' => [NotificationPriority::Low, Notification::IMPORTANCE_LOW];
        yield 'normal' => [NotificationPriority::Normal, Notification::IMPORTANCE_MEDIUM];
        yield 'high' => [NotificationPriority::High, Notification::IMPORTANCE_HIGH];
        yield 'urgent' => [NotificationPriority::Urgent, Notification::IMPORTANCE_URGENT];
    }
}
