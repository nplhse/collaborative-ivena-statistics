<?php

declare(strict_types=1);

namespace App\Import\Application\Repair;

use App\Allocation\Application\Indication\IndicationRawCorruptionMergeService;
use App\Allocation\Application\Indication\IndicationRawMergeResult;
use App\Import\Application\DTO\ImportRequeueBatchOptions;
use App\Import\Application\Service\ImportRequeueBatchOrchestrator;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;

final readonly class IndicationCorruptionRepairService
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI. */
    public function __construct(
        private IndicationRawCorruptionMergeService $mergeService,
        private QuoteBrokenImportDiscoveryService $discoveryService,
        private ImportRequeueBatchOrchestrator $requeueOrchestrator,
        private AllocationStatsProjectionRebuildInterface $projectionRebuilder,
    ) {
    }

    public function run(
        bool $dryRun,
        bool $skipMerge,
        bool $skipRequeue,
        bool $skipProjection,
        ?\DateTimeImmutable $since,
        ?int $onlyImportId,
    ): IndicationCorruptionRepairResult {
        $merge = $skipMerge
            ? new IndicationRawMergeResult(0, [], [])
            : $this->mergeService->run($dryRun);

        $discovery = $this->discoveryService->discover($since, $onlyImportId);

        $requeue = null;
        if (!$skipRequeue) {
            $requeue = $this->requeueOrchestrator->run(new ImportRequeueBatchOptions(
                dryRun: $dryRun,
                onlyIds: $discovery->readyImportIds(),
            ));
        }

        $projectionsRebuilt = 0;
        if (!$dryRun && !$skipProjection) {
            foreach ($merge->affectedImportIds as $importId) {
                $this->projectionRebuilder->rebuildForImport($importId);
                ++$projectionsRebuilt;
            }
        }

        return new IndicationCorruptionRepairResult($merge, $discovery, $requeue, $projectionsRebuilt);
    }
}
