<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/**
 * The lock store (semaphore) releases the lock when its process ends and ignores the TTL,
 * so holding the lock means being the only live run. The lock has no TTL so this also holds for other stores.
 * Each run claims requests under its own run id.
 */
final class ClosureVolumeProjectionRebuild implements ClosureVolumeProjectionRebuildInterface
{
    private const string LOCK = 'closure-volume-projection';

    private int $maxPartitionBytes = 0;

    public function __construct(
        private readonly ClosureAnalysisRebuilder $analysis,
        private readonly ClosureVolumeProjectionRebuilder $rebuilder,
        private readonly ClosureRebuildScheduler $scheduler,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    #[\Override]
    public function rebuild(?callable $onHospital = null, ?array $hospitalIds = null): int
    {
        $lock = $this->lockFactory->createLock(self::LOCK, null);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Closure volume projection rebuild is already running.');
        }

        try {
            $this->analysis->rebuild($hospitalIds);

            return $this->publishVolume(Uuid::v7()->toRfc4122(), $onHospital, $hospitalIds, ClosureRebuildClaim::empty());
        } finally {
            $lock->release();
        }
    }

    #[\Override]
    public function consume(bool $waitForLock = true): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK, null);
        if (!$lock->acquire($waitForLock)) {
            if ($waitForLock) {
                throw new \RuntimeException('Closure volume projection rebuild is already running.');
            }

            // The running build claims what is open now; anything later waits for the next check.
            return;
        }

        try {
            if (!$this->scheduler->hasPending()) {
                return;
            }
            $runId = Uuid::v7()->toRfc4122();
            while (true) {
                $analysis = $this->scheduler->claim($runId, 'analysis');
                $volume = $this->scheduler->claim($runId, 'volume');
                $work = $analysis->merge($volume);
                if ($work->isEmpty()) {
                    break;
                }
                try {
                    if (!$analysis->isEmpty()) {
                        $this->analysis->rebuild($analysis->all ? null : $analysis->hospitalIds);
                    }
                } catch (\Throwable $exception) {
                    $this->scheduler->release($work->requestIds, $exception);
                    throw $exception;
                }
                $this->publishVolume($runId, null, $work->all ? null : $work->hospitalIds, $work);
            }
        } finally {
            $lock->release();
        }
    }

    #[\Override]
    public function maxPartitionBytes(): int
    {
        return $this->maxPartitionBytes;
    }

    /**
     * Requests claimed for this run, including late ones, are completed after the publish and released if it fails.
     *
     * @param callable(int $processed, int $total, int $pass): void|null $onHospital
     * @param list<int>|null                                             $hospitalIds
     */
    private function publishVolume(string $runId, ?callable $onHospital, ?array $hospitalIds, ClosureRebuildClaim $claimed): int
    {
        try {
            $hospitals = $this->rebuilder->rebuild(
                $onHospital,
                $hospitalIds,
                function (?array $current) use ($runId, &$claimed): array {
                    $lateAnalysis = $this->scheduler->claim($runId, 'analysis', $current);
                    $lateVolume = $this->scheduler->claim($runId, 'volume', $current);
                    $claimed = $claimed->merge($lateAnalysis)->merge($lateVolume);
                    if (!$lateAnalysis->isEmpty()) {
                        $this->analysis->rebuild($lateAnalysis->all ? null : $lateAnalysis->hospitalIds);
                    }
                    $late = $lateAnalysis->merge($lateVolume);
                    if ($late->isEmpty()) {
                        return ['full' => null === $current, 'refresh' => []];
                    }

                    // Only hospitals with late requests are rebuilt again, also during a full rebuild.
                    return [
                        'full' => null === $current || $late->all,
                        'refresh' => $late->all ? $this->analysis->hospitalIds() : $late->hospitalIds,
                    ];
                },
            );
        } catch (\Throwable $exception) {
            $this->scheduler->release($claimed->requestIds, $exception);
            throw $exception;
        }
        $this->scheduler->complete($claimed->requestIds);
        $this->maxPartitionBytes = $this->rebuilder->maxPartitionBytes;

        return $hospitals;
    }
}
