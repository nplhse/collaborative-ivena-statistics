<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Infrastructure\EventSubscriber;

use App\Storage\Application\Event\StorageMeasured;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Infrastructure\EventSubscriber\StorageCapacityAlertSubscriber;
use App\Storage\Infrastructure\Notification\DiskUsagePage;
use App\Storage\Infrastructure\Notification\StorageCapacityAlertNotification;
use App\Tests\Support\Storage\FixedAdminRecipients;
use App\Tests\Support\Storage\InMemoryStorageCapacityAlertStore;
use App\Tests\Support\Storage\InMemoryStorageMeasurementRepository;
use App\Tests\Support\Storage\RecordingNotificationPublisher;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class StorageCapacityAlertSubscriberTest extends TestCase
{
    /** Used bytes of a 1000-byte budget that land on each level. */
    private const array USED = [
        'normal' => 500,
        'notice' => 850,
        'warning' => 950,
        'critical' => 995,
        'exceeded' => 1200,
    ];

    private InMemoryStorageCapacityAlertStore $store;
    private InMemoryStorageMeasurementRepository $repository;
    private RecordingNotificationPublisher $publisher;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->store = new InMemoryStorageCapacityAlertStore();
        $this->repository = new InMemoryStorageMeasurementRepository();
        $this->publisher = new RecordingNotificationPublisher();
    }

    /**
     * @return iterable<string, array{CapacityLevel, CapacityLevel, string}>
     */
    public static function changes(): iterable
    {
        $known = array_values(array_filter(CapacityLevel::cases(), static fn (CapacityLevel $level): bool => $level->isKnown()));
        foreach ($known as $from) {
            foreach ($known as $to) {
                if ($from === $to) {
                    continue;
                }

                $direction = match (true) {
                    (int) $to->rank() > (int) $from->rank() => 'escalated',
                    CapacityLevel::Normal === $to => 'resolved',
                    default => 'deescalated',
                };

                yield $from->value.' to '.$to->value => [$from, $to, $direction];
            }
        }
    }

    #[DataProvider('changes')]
    public function testEveryEscalationAndDeEscalationAlertsOnceAndIsRemembered(CapacityLevel $from, CapacityLevel $to, string $direction): void
    {
        $this->store->level = $from;

        $this->measure('2026-10-25', $to);

        self::assertCount(1, $this->publisher->requests);
        $request = $this->publisher->requests[0];
        self::assertSame(StorageCapacityAlertNotification::TYPE, $request->type);
        self::assertSame([7, 9], $request->recipientUserIds);
        self::assertSame(\sprintf('storage.capacity_alert:%s-%s:2026-10-25', $from->value, $to->value), $request->deduplicationKey);
        self::assertSame($direction, $request->parameters['direction']);
        self::assertSame($from->value, $request->parameters['from']);
        self::assertSame($to->value, $request->parameters['to']);
        self::assertSame('budget', $request->parameters['capacitySource']);
        self::assertSame(DiskUsagePage::ROUTE, $request->action?->route);
        self::assertSame(['reference' => 'storage_measurement:1'], $request->metadata);
        self::assertSame([[$to, '2026-10-25', '2026-10-25T03:31:00+00:00']], $this->store->recorded);
    }

    public function testFallingToNormalReadsAsResolved(): void
    {
        $this->store->level = CapacityLevel::Critical;

        $this->measure('2026-10-25', CapacityLevel::Normal);

        self::assertSame('resolved', $this->publisher->requests[0]->parameters['direction'] ?? null);
        self::assertSame(CapacityLevel::Normal, $this->store->level);
    }

    public function testAnUnchangedLevelSendsNothingHoweverManyDays(): void
    {
        $this->measure('2026-10-20', CapacityLevel::Warning);

        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'] as $day) {
            $this->measure($day, CapacityLevel::Warning);
        }

        self::assertCount(1, $this->publisher->requests, 'only the first day of the warning alerts');
        self::assertCount(1, $this->store->recorded);
    }

    public function testNormalWithoutEarlierAlertSendsNothing(): void
    {
        $this->measure('2026-10-25', CapacityLevel::Normal);

        self::assertSame([], $this->publisher->requests);
        self::assertSame([], $this->store->recorded);
    }

    public function testARiseAfterAFallAlertsAgain(): void
    {
        $this->measure('2026-10-20', CapacityLevel::Warning);
        $this->measure('2026-10-21', CapacityLevel::Notice);
        $this->measure('2026-10-22', CapacityLevel::Warning);
        $this->measure('2026-10-23', CapacityLevel::Normal);
        $this->measure('2026-10-24', CapacityLevel::Notice);

        self::assertSame([
            'storage.capacity_alert:normal-warning:2026-10-20',
            'storage.capacity_alert:warning-notice:2026-10-21',
            'storage.capacity_alert:notice-warning:2026-10-22',
            'storage.capacity_alert:warning-normal:2026-10-23',
            'storage.capacity_alert:normal-notice:2026-10-24',
        ], array_map(static fn (\App\Notification\Application\Publishing\NotificationRequest $request): string => $request->deduplicationKey, $this->publisher->requests));
        self::assertSame(
            ['escalated', 'deescalated', 'escalated', 'resolved', 'escalated'],
            array_map(static fn (\App\Notification\Application\Publishing\NotificationRequest $request): mixed => $request->parameters['direction'], $this->publisher->requests),
        );
    }

    public function testARerunOnTheSameDayIsHarmless(): void
    {
        $this->measure('2026-10-25', CapacityLevel::Critical);
        $this->measure('2026-10-25', CapacityLevel::Critical);

        self::assertCount(1, $this->publisher->requests, 'the level was recorded, so the rerun is no change');

        // Even if the level could not be recorded, the rerun reuses the key and the publisher drops it.
        $this->store->level = CapacityLevel::Normal;
        $this->measure('2026-10-25', CapacityLevel::Critical);

        self::assertCount(2, $this->publisher->requests);
        self::assertSame($this->publisher->requests[0]->deduplicationKey, $this->publisher->requests[1]->deduplicationKey);
        self::assertCount(1, $this->publisher->published);
    }

    public function testAnUnknownLevelSendsNothingAndChangesNothing(): void
    {
        $this->store->level = CapacityLevel::Warning;

        $this->measure('2026-10-25', CapacityLevel::Unknown);

        self::assertSame([], $this->publisher->requests);
        self::assertSame([], $this->store->recorded);
        self::assertSame(CapacityLevel::Warning, $this->store->level);

        $this->measure('2026-10-26', CapacityLevel::Warning);
        self::assertSame([], $this->publisher->requests, 'still the same level as before the unknown day');
    }

    public function testWithoutAdministratorsNothingIsSentOrRemembered(): void
    {
        $this->measure('2026-10-25', CapacityLevel::Critical, $this->subscriber(new FixedAdminRecipients([])));

        self::assertSame([], $this->publisher->requests);
        self::assertSame([], $this->store->recorded, 'the next administrator still learns about the level');
    }

    public function testAMissingOrReplacedMeasurementSendsNothing(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onStorageMeasured(new StorageMeasured(41, new \DateTimeImmutable('2026-10-25'), CapacityLevel::Critical));
        $this->repository->save(StorageMeasurementBuilder::create(usedBytes: 995, level: CapacityLevel::Critical, id: 42));
        $subscriber->onStorageMeasured(new StorageMeasured(41, new \DateTimeImmutable('2026-10-25'), CapacityLevel::Critical));

        self::assertSame([], $this->publisher->requests);
        self::assertSame(CapacityLevel::Normal, $this->store->level);
    }

    private function measure(string $day, CapacityLevel $level, ?StorageCapacityAlertSubscriber $subscriber = null): void
    {
        $measurement = StorageMeasurementBuilder::create(
            day: $day,
            usedBytes: self::USED[$level->value] ?? null,
            level: $level,
            source: CapacitySource::Budget,
            id: $this->nextId++,
        );
        $this->repository->save($measurement);

        ($subscriber ?? $this->subscriber())->onStorageMeasured(new StorageMeasured((int) $measurement->getId(), $measurement->getMeasuredOn(), $level));
    }

    private function subscriber(?FixedAdminRecipients $recipients = null): StorageCapacityAlertSubscriber
    {
        return new StorageCapacityAlertSubscriber(
            $this->store,
            $this->repository,
            $recipients ?? new FixedAdminRecipients(),
            $this->publisher,
            new MockClock(new \DateTimeImmutable('2026-10-25 03:31:00', new \DateTimeZone('UTC'))),
        );
    }
}
