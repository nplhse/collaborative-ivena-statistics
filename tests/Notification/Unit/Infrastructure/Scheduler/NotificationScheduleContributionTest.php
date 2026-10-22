<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Infrastructure\Scheduler;

use App\Notification\Application\Message\CleanUpNotificationsMessage;
use App\Notification\Infrastructure\Scheduler\NotificationScheduleContribution;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class NotificationScheduleContributionTest extends TestCase
{
    public function testSchedulesDailyCleanup(): void
    {
        $messages = new NotificationScheduleContribution()->recurringMessages();
        self::assertCount(1, $messages);

        $recurring = $messages[0];
        $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());
        $scheduled = [...$recurring->getMessages($context)];

        self::assertCount(1, $scheduled);
        self::assertInstanceOf(CleanUpNotificationsMessage::class, $scheduled[0]);
        self::assertStringContainsString('45 2 * * *', (string) $recurring->getTrigger());
    }
}
