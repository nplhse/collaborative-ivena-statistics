<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Infrastructure\Scheduler;

use App\Notification\Application\Message\CleanUpNotificationsMessage;
use App\Notification\Application\Message\ReconcileNotificationsMessage;
use App\Notification\Infrastructure\Scheduler\NotificationScheduleContribution;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

final class NotificationScheduleContributionTest extends TestCase
{
    public function testSchedulesDailyCleanupAndHourlyReconciliation(): void
    {
        $messages = new NotificationScheduleContribution()->recurringMessages();
        self::assertCount(2, $messages);

        self::assertInstanceOf(CleanUpNotificationsMessage::class, $this->message($messages[0]));
        self::assertStringContainsString('45 2 * * *', (string) $messages[0]->getTrigger());
        self::assertInstanceOf(ReconcileNotificationsMessage::class, $this->message($messages[1]));
        self::assertStringContainsString('17 * * * *', (string) $messages[1]->getTrigger());
    }

    private function message(RecurringMessage $recurring): object
    {
        $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());
        $scheduled = [...$recurring->getMessages($context)];
        self::assertCount(1, $scheduled);

        return $scheduled[0];
    }
}
