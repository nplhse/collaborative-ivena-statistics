<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Infrastructure\Scheduler;

use App\Storage\Application\Message\MeasureStorageMessage;
use App\Storage\Infrastructure\Scheduler\StorageScheduleContribution;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class StorageScheduleContributionTest extends TestCase
{
    public function testMeasuresDailyAtHalfPastThreeInBerlin(): void
    {
        $messages = new StorageScheduleContribution()->recurringMessages();
        self::assertCount(1, $messages);

        $trigger = $messages[0]->getTrigger();
        self::assertStringContainsString('30 3 * * *', (string) $trigger);
        self::assertSame(
            '2026-10-26 03:30 +01:00',
            $trigger->getNextRunDate(new \DateTimeImmutable('2026-10-25 12:00', new \DateTimeZone('Europe/Berlin')))?->format('Y-m-d H:i P'),
        );

        $context = new MessageContext('default', $messages[0]->getId(), $trigger, new \DateTimeImmutable());
        $scheduled = [...$messages[0]->getMessages($context)];
        self::assertCount(1, $scheduled);
        self::assertInstanceOf(MeasureStorageMessage::class, $scheduled[0]);
    }
}
