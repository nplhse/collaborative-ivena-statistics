<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use PHPUnit\Framework\TestCase;

final class MonthlyReminderDispatchTest extends TestCase
{
    public function testLifecycleMethodsUpdateDeliveryFields(): void
    {
        $queuedAt = new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('Europe/Berlin'));
        $deliveredAt = new \DateTimeImmutable('2026-07-01 08:01:00', new \DateTimeZone('Europe/Berlin'));
        $hospital = $this->createMock(Hospital::class);

        $dispatch = new MonthlyReminderDispatch(
            $hospital,
            '2026-06',
            MonthlyReminderTrigger::Admin->value,
            $queuedAt,
            MonthlyReminderDispatchStatus::Queued,
            'owner@example.test',
        );

        self::assertSame($hospital, $dispatch->getHospital());
        self::assertSame('2026-06', $dispatch->getReportingPeriod());
        self::assertSame(MonthlyReminderTrigger::Admin->value, $dispatch->getTrigger());
        self::assertSame($queuedAt, $dispatch->getSentAt());
        self::assertSame(MonthlyReminderDispatchStatus::Queued, $dispatch->getStatus());
        self::assertSame('owner@example.test', $dispatch->getRecipientEmail());
        self::assertNull($dispatch->getFailureReason());
        self::assertNull($dispatch->getDeliveredAt());

        $dispatch->markFailed('SMTP rate limit');
        self::assertSame(MonthlyReminderDispatchStatus::Failed, $dispatch->getStatus());
        self::assertSame('SMTP rate limit', $dispatch->getFailureReason());

        $dispatch->prepareForSend('owner@example.test', $queuedAt);
        self::assertSame(MonthlyReminderDispatchStatus::Queued, $dispatch->getStatus());
        self::assertNull($dispatch->getFailureReason());
        self::assertNull($dispatch->getDeliveredAt());

        $dispatch->markSent($deliveredAt);
        self::assertSame(MonthlyReminderDispatchStatus::Sent, $dispatch->getStatus());
        self::assertSame($deliveredAt, $dispatch->getDeliveredAt());
        self::assertNull($dispatch->getFailureReason());
    }
}
