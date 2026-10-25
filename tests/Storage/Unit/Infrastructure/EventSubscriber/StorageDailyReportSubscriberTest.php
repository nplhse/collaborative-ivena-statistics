<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Infrastructure\EventSubscriber;

use App\Storage\Application\Event\StorageMeasured;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Infrastructure\EventSubscriber\StorageDailyReportSubscriber;
use App\Storage\Infrastructure\Notification\DiskUsagePage;
use App\Storage\Infrastructure\Notification\StorageDailyReportNotification;
use App\Tests\Support\Storage\FixedAdminRecipients;
use App\Tests\Support\Storage\InMemoryStorageMeasurementRepository;
use App\Tests\Support\Storage\RecordingNotificationPublisher;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use PHPUnit\Framework\TestCase;

final class StorageDailyReportSubscriberTest extends TestCase
{
    private RecordingNotificationPublisher $publisher;

    protected function setUp(): void
    {
        $this->publisher = new RecordingNotificationPublisher();
    }

    public function testPublishesTheReportOfTheDayComparedWithThePreviousMeasurement(): void
    {
        $today = StorageMeasurementBuilder::create(day: '2026-10-25', usedBytes: 900, id: 3);
        $repository = new InMemoryStorageMeasurementRepository(
            StorageMeasurementBuilder::create(day: '2026-09-01', usedBytes: 100, id: 1),
            StorageMeasurementBuilder::create(day: '2026-10-22', usedBytes: 700, id: 2),
            $today,
        );

        $this->subscriber($repository)->onStorageMeasured($this->event($today));

        self::assertCount(1, $this->publisher->requests);
        $request = $this->publisher->requests[0];
        self::assertSame(StorageDailyReportNotification::TYPE, $request->type);
        self::assertSame([7, 9], $request->recipientUserIds);
        self::assertSame('storage.daily_report:2026-10-25', $request->deduplicationKey);
        self::assertSame('900 B', $request->parameters['used']);
        self::assertSame('up', $request->parameters['changeDirection']);
        self::assertSame('200 B', $request->parameters['change']);
        self::assertSame(3, $request->parameters['daysSincePrevious']);
        self::assertSame(DiskUsagePage::ROUTE, $request->action?->route);
        self::assertSame(['reference' => 'storage_measurement:3'], $request->metadata);
    }

    public function testTheFirstMeasurementHasNoChange(): void
    {
        $today = StorageMeasurementBuilder::create(id: 1);
        $repository = new InMemoryStorageMeasurementRepository(StorageMeasurementBuilder::create(day: '2026-09-01', id: 9), $today);

        $this->subscriber($repository)->onStorageMeasured($this->event($today));

        self::assertSame('unknown', $this->publisher->requests[0]->parameters['changeDirection'] ?? null, 'older than the window');
    }

    public function testAnUnknownLevelStillGetsAReport(): void
    {
        $today = StorageMeasurementBuilder::create(usedBytes: null, level: CapacityLevel::Unknown, id: 1);

        $this->subscriber(new InMemoryStorageMeasurementRepository($today))->onStorageMeasured($this->event($today));

        self::assertSame('unavailable', $this->publisher->requests[0]->parameters['usage'] ?? null);
    }

    public function testNothingWithoutAdministratorsOrWithoutTheMeasurement(): void
    {
        $today = StorageMeasurementBuilder::create(id: 1);
        $repository = new InMemoryStorageMeasurementRepository($today);

        $this->subscriber($repository, new FixedAdminRecipients([]))->onStorageMeasured($this->event($today));
        $this->subscriber($repository)->onStorageMeasured(new StorageMeasured(2, $today->getMeasuredOn(), CapacityLevel::Notice));
        $this->subscriber($repository)->onStorageMeasured(new StorageMeasured(1, new \DateTimeImmutable('2026-10-26'), CapacityLevel::Notice));

        self::assertSame([], $this->publisher->requests);
    }

    private function event(StorageMeasurement $measurement): StorageMeasured
    {
        return new StorageMeasured((int) $measurement->getId(), $measurement->getMeasuredOn(), $measurement->getLevel());
    }

    private function subscriber(InMemoryStorageMeasurementRepository $repository, ?FixedAdminRecipients $recipients = null): StorageDailyReportSubscriber
    {
        return new StorageDailyReportSubscriber($repository, $recipients ?? new FixedAdminRecipients(), $this->publisher);
    }
}
