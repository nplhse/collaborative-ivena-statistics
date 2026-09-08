<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Infrastructure\Scheduler;

use App\Analytics\Application\Message\AggregateDailyAnalyticsMessage;
use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Kpi\Application\Message\GenerateDailyKpisMessage;
use App\Shared\Infrastructure\Scheduler\DefaultScheduleProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;

final class DefaultScheduleProviderKernelTest extends KernelTestCase
{
    public function testContainerWiresKpiReminderAndAnalyticsContributions(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(DefaultScheduleProvider::class);
        $recurringMessages = $provider->getSchedule()->getRecurringMessages();
        self::assertCount(3, $recurringMessages);

        $classes = [];
        foreach ($recurringMessages as $recurringMessage) {
            self::assertInstanceOf(RecurringMessage::class, $recurringMessage);
            $trigger = $recurringMessage->getTrigger();
            self::assertInstanceOf(CronExpressionTrigger::class, $trigger);
            $messageProvider = $recurringMessage->getProvider();
            self::assertInstanceOf(StaticMessageProvider::class, $messageProvider);
            $messages = iterator_to_array($messageProvider->getMessages(new MessageContext(
                name: 'default',
                id: 'kernel-'.(string) $trigger,
                trigger: $trigger,
                triggeredAt: new \DateTimeImmutable(),
            )));
            self::assertCount(1, $messages);
            $classes[] = $messages[0]::class;
        }

        self::assertContains(GenerateDailyKpisMessage::class, $classes);
        self::assertContains(SendMonthlySubmissionRemindersMessage::class, $classes);
        self::assertContains(AggregateDailyAnalyticsMessage::class, $classes);
    }
}
