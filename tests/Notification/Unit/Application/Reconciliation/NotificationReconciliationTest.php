<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Reconciliation;

use App\Notification\Application\Contract\NotificationReconcilerInterface;
use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Reconciliation\NotificationReconciliation;
use App\Tests\Support\Notification\Type\InAppTestNotification;
use App\Tests\Support\Notification\Type\ToastTestNotification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class NotificationReconciliationTest extends TestCase
{
    public function testRecentRunCoversTheLast24HoursOfEveryReconciler(): void
    {
        $first = new RecordingReconciler(2);
        $second = new RecordingReconciler(3);

        $published = $this->reconciliation([$first, $second])->reconcileRecent();

        self::assertSame(5, $published);
        self::assertSame([['2026-10-21 12:00:00', '2026-10-22 12:00:00']], $first->ranges);
        self::assertSame($first->ranges, $second->ranges);
    }

    public function testRejectsEmptyRanges(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be before its end');

        $this->reconciliation([])->reconcile(new \DateTimeImmutable('2026-10-22 10:00:00'), new \DateTimeImmutable('2026-10-22 10:00:00'));
    }

    public function testRejectsRangesBeyondTheShortestDeduplicationPeriod(): void
    {
        $reconciliation = $this->reconciliation([new RecordingReconciler(0)]);

        self::assertSame('2026-10-15 12:00:00', $reconciliation->earliestAllowedStart()->format('Y-m-d H:i:s'));
        $utc = new \DateTimeZone('UTC');
        self::assertSame(0, $reconciliation->reconcile(new \DateTimeImmutable('2026-10-15 12:00:00', $utc), new \DateTimeImmutable('2026-10-16 12:00:00', $utc)));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('may not start before 2026-10-15 12:00:00');

        $reconciliation->reconcile(new \DateTimeImmutable('2026-10-15 11:59:59', $utc), new \DateTimeImmutable('2026-10-16 12:00:00', $utc));
    }

    public function testWithoutRegisteredTypesOnlyTheRegularWindowIsAllowed(): void
    {
        $reconciliation = new NotificationReconciliation([], new NotificationRegistry(), new MockClock('2026-10-22 12:00:00'), new CountingResumer(0));

        self::assertSame('2026-10-21 12:00:00', $reconciliation->earliestAllowedStart()->format('Y-m-d H:i:s'));
    }

    /**
     * @param list<NotificationReconcilerInterface> $reconcilers
     */
    private function reconciliation(array $reconcilers): NotificationReconciliation
    {
        return new NotificationReconciliation(
            $reconcilers,
            new NotificationRegistry(['test.in_app' => InAppTestNotification::class, 'test.toast' => ToastTestNotification::class]),
            new MockClock('2026-10-22 12:00:00'),
            new CountingResumer(4),
        );
    }

    public function testPendingDeliveriesAreResumedOutsideAnyRange(): void
    {
        self::assertSame(4, $this->reconciliation([])->resumePendingDeliveries());
    }
}

final class RecordingReconciler implements NotificationReconcilerInterface
{
    /** @var list<array{string, string}> */
    public array $ranges = [];

    public function __construct(private readonly int $published)
    {
    }

    public function reconcile(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $this->ranges[] = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];

        return $this->published;
    }
}

final readonly class CountingResumer implements \App\Notification\Application\Contract\PendingDeliveryResumerInterface
{
    public function __construct(private int $resumed)
    {
    }

    #[\Override]
    public function resume(): int
    {
        return $this->resumed;
    }
}
