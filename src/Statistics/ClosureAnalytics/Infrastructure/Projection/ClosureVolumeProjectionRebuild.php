<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Projection;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use Symfony\Component\Lock\LockFactory;

final class ClosureVolumeProjectionRebuild implements ClosureVolumeProjectionRebuildInterface
{
    private int $maxPartitionBytes = 0;

    public function __construct(
        private readonly ClosureAnalysisRebuilder $analysis,
        private readonly ClosureVolumeProjectionRebuilder $rebuilder,
        private readonly ClosureRebuildScheduler $scheduler,
        private readonly LockFactory $lockFactory,
    ) {
    }

    #[\Override]
    public function rebuild(?callable $onHospital = null): int
    {
        $lock = $this->lockFactory->createLock('closure-volume-projection', 7200.0);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Closure volume projection rebuild is already running.');
        }

        try {
            $this->analysis->rebuild(null);

            return $this->publishVolume($onHospital, null);
        } finally {
            $lock->release();
        }
    }

    #[\Override]
    public function consume(): void
    {
        $lock = $this->lockFactory->createLock('closure-volume-projection', 7200.0);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Closure volume projection rebuild is already running.');
        }

        try {
            while (true) {
                $analysis = $this->scheduler->claim('analysis');
                if (!$analysis->isEmpty()) {
                    $this->analysis->rebuild($analysis->all ? null : $analysis->hospitalIds);
                }
                $volume = $this->scheduler->claim('volume');
                $work = $analysis->merge($volume);
                if ($work->isEmpty()) {
                    break;
                }
                $this->publishVolume(null, $work->all ? null : $work->hospitalIds);
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
     * @param callable(int $processed, int $total): void|null $onHospital
     * @param list<int>|null                                  $hospitalIds
     */
    private function publishVolume(?callable $onHospital, ?array $hospitalIds): int
    {
        $hospitals = $this->rebuilder->rebuild(
            $onHospital,
            $hospitalIds,
            function (?array $current): array {
                $lateAnalysis = $this->scheduler->claim('analysis', $current);
                if (!$lateAnalysis->isEmpty()) {
                    $this->analysis->rebuild($lateAnalysis->all ? null : $lateAnalysis->hospitalIds);
                }
                $lateVolume = $this->scheduler->claim('volume', $current);
                $merged = new ClosureRebuildClaim(null === $current, $current ?? [])->merge($lateAnalysis)->merge($lateVolume);
                if ($lateAnalysis->isEmpty() && $lateVolume->isEmpty()) {
                    return ['full' => null === $current, 'refresh' => []];
                }

                return [
                    'full' => $merged->all,
                    'refresh' => $merged->all ? $this->analysis->hospitalIds() : $merged->hospitalIds,
                ];
            },
        );
        $this->maxPartitionBytes = $this->rebuilder->maxPartitionBytes;

        return $hospitals;
    }
}
