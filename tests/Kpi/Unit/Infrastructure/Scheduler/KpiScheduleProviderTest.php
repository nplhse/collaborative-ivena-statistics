<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Unit\Infrastructure\Scheduler;

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

        self::assertCount(1, $recurringMessages);

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
    }
}
