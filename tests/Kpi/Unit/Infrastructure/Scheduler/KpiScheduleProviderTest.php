<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Unit\Infrastructure\Scheduler;

use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Kpi\Application\Message\GenerateDailyKpisMessage;
use App\Kpi\Infrastructure\Scheduler\KpiScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;
use Symfony\Contracts\Cache\CacheInterface;

final class KpiScheduleProviderTest extends TestCase
{
    public function testScheduleDispatchesGenerateDailyKpisMessageEverySixHours(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $provider = new KpiScheduleProvider($cache);

        $recurringMessages = $provider->getSchedule()->getRecurringMessages();

        self::assertCount(2, $recurringMessages);

        $recurringMessage = $recurringMessages[0];
        self::assertInstanceOf(RecurringMessage::class, $recurringMessage);

        $trigger = $recurringMessage->getTrigger();
        self::assertInstanceOf(CronExpressionTrigger::class, $trigger);
        self::assertSame('0 */6 * * *', (string) $trigger);

        $messageProvider = $recurringMessage->getProvider();
        self::assertInstanceOf(StaticMessageProvider::class, $messageProvider);

        $messages = iterator_to_array($messageProvider->getMessages(new MessageContext(
            name: 'default',
            id: 'test',
            trigger: $trigger,
            triggeredAt: new \DateTimeImmutable(),
        )));

        self::assertCount(1, $messages);
        self::assertInstanceOf(GenerateDailyKpisMessage::class, $messages[0]);

        $monthlyMessage = $recurringMessages[1];
        self::assertInstanceOf(RecurringMessage::class, $monthlyMessage);
        $monthlyTrigger = $monthlyMessage->getTrigger();
        self::assertInstanceOf(CronExpressionTrigger::class, $monthlyTrigger);
        self::assertSame('0 8 1-7 * 1', (string) $monthlyTrigger);

        $monthlyProvider = $monthlyMessage->getProvider();
        self::assertInstanceOf(StaticMessageProvider::class, $monthlyProvider);
        $monthlyMessages = iterator_to_array($monthlyProvider->getMessages(new MessageContext(
            name: 'default',
            id: 'monthly-test',
            trigger: $monthlyTrigger,
            triggeredAt: new \DateTimeImmutable(),
        )));
        self::assertCount(1, $monthlyMessages);
        self::assertInstanceOf(SendMonthlySubmissionRemindersMessage::class, $monthlyMessages[0]);
    }
}
