<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Scheduler;

use App\Analytics\Application\Message\AggregateDailyAnalyticsMessage;
use App\Analytics\Infrastructure\Scheduler\AnalyticsScheduleContribution;
use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Engagement\Infrastructure\Scheduler\ReminderScheduleContribution;
use App\Kpi\Application\Message\GenerateDailyKpisMessage;
use App\Kpi\Infrastructure\Scheduler\KpiScheduleContribution;
use App\Shared\Infrastructure\Scheduler\DefaultScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;
use Symfony\Contracts\Cache\CacheInterface;

final class DefaultScheduleProviderTest extends TestCase
{
    public function testScheduleIncludesKpiReminderAndAnalyticsJobs(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $provider = new DefaultScheduleProvider($cache, [
            new KpiScheduleContribution(),
            new ReminderScheduleContribution(),
            new AnalyticsScheduleContribution(),
        ]);

        $recurringMessages = $provider->getSchedule()->getRecurringMessages();
        self::assertCount(3, $recurringMessages);

        $byCron = [];
        foreach ($recurringMessages as $recurringMessage) {
            self::assertInstanceOf(RecurringMessage::class, $recurringMessage);
            $trigger = $recurringMessage->getTrigger();
            self::assertInstanceOf(CronExpressionTrigger::class, $trigger);
            $messageProvider = $recurringMessage->getProvider();
            self::assertInstanceOf(StaticMessageProvider::class, $messageProvider);
            $messages = iterator_to_array($messageProvider->getMessages(new MessageContext(
                name: 'default',
                id: 'test-'.(string) $trigger,
                trigger: $trigger,
                triggeredAt: new \DateTimeImmutable(),
            )));
            self::assertCount(1, $messages);
            $byCron[(string) $trigger] = $messages[0];
        }

        self::assertInstanceOf(GenerateDailyKpisMessage::class, $byCron['0 */6 * * *'] ?? null);
        self::assertInstanceOf(SendMonthlySubmissionRemindersMessage::class, $byCron['0 8 * * *'] ?? null);
        self::assertInstanceOf(AggregateDailyAnalyticsMessage::class, $byCron['15 2 * * *'] ?? null);
    }
}
