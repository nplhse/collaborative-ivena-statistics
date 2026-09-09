<?php

declare(strict_types=1);

namespace App\Import\Application\Repair;

use App\Allocation\Application\Indication\IndicationRawMergeResult;
use App\Import\Application\DTO\ImportRequeueBatchSummary;

final readonly class IndicationCorruptionRepairResult
{
    public function __construct(
        public IndicationRawMergeResult $merge,
        public QuoteBrokenImportDiscoveryResult $discovery,
        public ?ImportRequeueBatchSummary $requeue,
        public int $projectionsRebuilt,
    ) {
    }
}
