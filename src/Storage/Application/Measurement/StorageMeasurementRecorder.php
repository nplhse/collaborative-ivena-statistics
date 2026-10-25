<?php

declare(strict_types=1);

namespace App\Storage\Application\Measurement;

use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\Event\StorageMeasured;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Measures, stores the day's row and announces it (ADR 017). Used by the scheduled job and by
 * `app:storage:analyze --persist`; the lock keeps the two from walking the disk at the same time.
 */
final readonly class StorageMeasurementRecorder
{
    private const string LOCK_KEY = 'storage-measurement';

    /** Long enough for a full walk of all releases; released as soon as the run ends. */
    private const float LOCK_TTL_SECONDS = 3600.0;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private StorageAnalyzer $analyzer,
        private StorageMeasurementRepositoryInterface $repository,
        private EventDispatcherInterface $dispatcher,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @param bool $announce dispatch StorageMeasured, which triggers alerts and the daily report
     *
     * @throws StorageMeasurementInProgress when another measurement is running
     */
    public function record(bool $announce = true): RecordedMeasurement
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            throw new StorageMeasurementInProgress('Another storage measurement is running.');
        }

        try {
            $analysis = $this->analyzer->analyze();
            $stored = $this->repository->save($analysis->measurement);
            $id = $stored->getId() ?? throw new \LogicException('A stored storage measurement has an id.');

            if ($announce) {
                $this->dispatcher->dispatch(new StorageMeasured($id, $stored->getMeasuredOn(), $stored->getLevel()));
            }

            return new RecordedMeasurement($analysis, $stored, $stored !== $analysis->measurement, $announce);
        } finally {
            $lock->release();
        }
    }
}
