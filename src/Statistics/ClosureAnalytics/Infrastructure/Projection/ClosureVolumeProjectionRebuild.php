<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceWindow;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildTelemetry;
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
        private readonly ClosureRebuildTelemetry $telemetry,
        private readonly ClosureVolumeReferenceConfig $config,
        private readonly ClosureVolumeRebuildPlanner $planner,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    #[\Override]
    public function rebuild(?callable $onHospital = null, ?array $hospitalIds = null, ?string $referenceWindow = null): int
    {
        $window = null === $referenceWindow ? null : ClosureVolumeReferenceWindow::named($referenceWindow, $this->config->window);
        $lock = $this->lockFactory->createLock(self::LOCK, null);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Closure volume projection rebuild is already running.');
        }

        $runId = Uuid::v7()->toRfc4122();
        $this->telemetry->begin($runId, 'console', $hospitalIds);
        $error = null;
        try {
            // Requests this run covers are taken now, so they do not trigger a second pass after the first one.
            $claimed = $this->scheduler->claim($runId, 'analysis', $hospitalIds, false)
                ->merge($this->scheduler->claim($runId, 'volume', $hospitalIds, false));
            $this->telemetry->claimedRequests(\count($claimed->requestIds));
            try {
                $this->rebuildAnalysis($hospitalIds);
            } catch (\Throwable $exception) {
                $this->scheduler->release($claimed->requestIds, $exception);
                throw $exception;
            }

            return $this->publishVolume($runId, $onHospital, $hospitalIds, $claimed, $window);
        } catch (\Throwable $exception) {
            $error = $exception;
            throw $exception;
        } finally {
            $this->telemetry->end($error);
            $lock->release();
        }
    }

    #[\Override]
    public function consume(bool $waitForLock = true, ?string $trigger = null): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK, null);
        if (!$lock->acquire($waitForLock)) {
            if ($waitForLock) {
                throw new \RuntimeException('Closure volume projection rebuild is already running.');
            }

            // The running build claims what is open now; anything later waits for the next check.
            return;
        }

        $began = false;
        $error = null;
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
                if (!$began) {
                    // No run is recorded when the check finds nothing to do.
                    $this->telemetry->begin($runId, $trigger ?? ($waitForLock ? 'messenger' : 'scheduler'), $work->all ? null : $work->hospitalIds);
                    $began = true;
                }
                $this->telemetry->claimedRequests(\count($work->requestIds));
                try {
                    $changes = $analysis->isEmpty() ? [] : $this->rebuildAnalysis($analysis->all ? null : $analysis->hospitalIds);
                    $scope = $this->eventScope($work, $changes);
                } catch (\Throwable $exception) {
                    $this->scheduler->release($work->requestIds, $exception);
                    throw $exception;
                }
                $this->publishVolume($runId, null, $work->all ? null : $work->hospitalIds, $work, null, $scope);
            }
        } catch (\Throwable $exception) {
            $error = $exception;
            throw $exception;
        } finally {
            if ($began) {
                $this->telemetry->end($error);
            }
            $lock->release();
        }
    }

    #[\Override]
    public function maxPartitionBytes(): int
    {
        return $this->maxPartitionBytes;
    }

    #[\Override]
    public function lastProfile(): array
    {
        return $this->rebuilder->profile->steps();
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<int, ClosureAnalysisChange>
     */
    private function rebuildAnalysis(?array $hospitalIds): array
    {
        $this->telemetry->startSpan('closure.rebuild.analysis', ['full' => null === $hospitalIds, 'hospital_ids' => $hospitalIds ?? []]);
        $changes = $this->analysis->rebuild($hospitalIds);
        $this->telemetry->finishSpan();

        return $changes;
    }

    /**
     * Events to rebuild per hospital whose requests all carry a range (ADR 015 phase 4).
     * Hospitals left out are rebuilt whole.
     *
     * @param array<int, ClosureAnalysisChange> $changes
     *
     * @return array<int, list<int>>
     */
    private function eventScope(ClosureRebuildClaim $work, array $changes): array
    {
        if ($work->all) {
            return [];
        }
        $scope = [];
        foreach ($work->hospitalIds as $hospitalId) {
            $ranges = $work->rangesFor($hospitalId);
            if (null === $ranges) {
                continue;
            }
            $change = $changes[$hospitalId] ?? null;
            $scope[$hospitalId] = $this->planner->affectedEvents(
                $hospitalId,
                [...$ranges, ...($change->changedRanges ?? [])],
                $change->changedEventIds ?? [],
                $this->config->window,
            );
        }

        return $scope;
    }

    /**
     * Requests claimed for this run, including late ones, are completed after the publish and released if it fails.
     *
     * @param callable(int $processed, int $total, int $pass): void|null $onHospital
     * @param list<int>|null                                             $hospitalIds
     * @param array<int, list<int>>                                      $eventScope  hospitals rebuilt for these events only
     */
    private function publishVolume(
        string $runId,
        ?callable $onHospital,
        ?array $hospitalIds,
        ClosureRebuildClaim $claimed,
        ?ClosureVolumeReferenceWindow $window = null,
        array $eventScope = [],
    ): int {
        try {
            $hospitals = $this->rebuilder->rebuild(
                $onHospital,
                $hospitalIds,
                function (?array $current) use ($runId, &$claimed): array {
                    $lateAnalysis = $this->scheduler->claim($runId, 'analysis', $current);
                    $lateVolume = $this->scheduler->claim($runId, 'volume', $current);
                    $claimed = $claimed->merge($lateAnalysis)->merge($lateVolume);
                    $this->telemetry->claimedRequests(\count($lateAnalysis->requestIds) + \count($lateVolume->requestIds));
                    if (!$lateAnalysis->isEmpty()) {
                        $this->rebuildAnalysis($lateAnalysis->all ? null : $lateAnalysis->hospitalIds);
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
                $window,
                $eventScope,
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
