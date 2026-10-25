<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Application;

use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Application\Event\StorageMeasured;
use App\Storage\Application\Layout\StorageLayout;
use App\Storage\Application\Measurement\StorageMeasurementInProgress;
use App\Storage\Application\Measurement\StorageMeasurementRecorder;
use App\Storage\Application\Message\MeasureStorageMessage;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

final class StorageMeasurementRecorderTest extends DatabaseKernelTestCase
{
    private StorageFixtureDirectory $fixture;

    /** @var list<StorageMeasured> */
    private array $announced = [];

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
        $this->fixture->file('vendor/pkg/lib.php', 7000);
        $this->fixture->file('var/imports/a.csv', 5000);

        self::bootKernel();
        $root = $this->fixture->path;
        self::getContainer()->set(StorageLayoutDetectorInterface::class, new readonly class($root) implements StorageLayoutDetectorInterface {
            public function __construct(private string $root)
            {
            }

            public function detect(): StorageLayout
            {
                return StorageLayout::standalone($this->root);
            }
        });

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(StorageMeasured::class, function (StorageMeasured $event): void {
            $this->announced[] = $event;
        });
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testStoresTheDayAndAnnouncesIt(): void
    {
        $recorded = $this->recorder()->record();

        self::assertNotNull($recorded->measurement->getId());
        self::assertFalse($recorded->replacedEarlierRun);
        self::assertTrue($recorded->announced);
        self::assertSame(1, $this->rowCount());
        self::assertCount(1, $this->announced);
        self::assertSame($recorded->measurement->getId(), $this->announced[0]->measurementId);
        self::assertSame($recorded->measurement->getLevel(), $this->announced[0]->level);
        self::assertSame($recorded->measurement->getMeasuredOn()->format('Y-m-d'), $this->announced[0]->measuredOn->format('Y-m-d'));
    }

    public function testASecondRunReplacesTheDayWithoutAnnouncing(): void
    {
        $first = $this->recorder()->record();
        $second = $this->recorder()->record(announce: false);

        self::assertTrue($second->replacedEarlierRun);
        self::assertFalse($second->announced);
        self::assertSame($first->measurement->getId(), $second->measurement->getId());
        self::assertSame(1, $this->rowCount());
        self::assertCount(1, $this->announced);
    }

    public function testRefusesToRunWhileAnotherMeasurementHoldsTheLock(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock('storage-measurement');
        self::assertTrue($lock->acquire());

        try {
            $this->recorder()->record();
            self::fail('Expected the measurement to be refused.');
        } catch (StorageMeasurementInProgress) {
            self::assertSame(0, $this->rowCount());
        } finally {
            $lock->release();
        }

        $this->recorder()->record();
        self::assertSame(1, $this->rowCount(), 'the lock is free again afterwards');
    }

    public function testTheScheduledMessageRecordsAMeasurement(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MeasureStorageMessage());

        self::assertSame(1, $this->rowCount());
        self::assertCount(1, $this->announced);
    }

    public function testTheScheduledMessageIsSkippedWhileAnotherMeasurementRuns(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock('storage-measurement');
        self::assertTrue($lock->acquire());

        try {
            self::getContainer()->get(MessageBusInterface::class)->dispatch(new MeasureStorageMessage());
        } finally {
            $lock->release();
        }

        self::assertSame(0, $this->rowCount());
        self::assertSame([], $this->announced);
    }

    private function recorder(): StorageMeasurementRecorder
    {
        return self::getContainer()->get(StorageMeasurementRecorder::class);
    }

    private function rowCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM storage_measurement');
    }
}
