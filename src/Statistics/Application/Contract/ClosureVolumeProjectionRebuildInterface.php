<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface ClosureVolumeProjectionRebuildInterface
{
    /**
     * @param callable(int $processed, int $total): void|null $onHospital
     * @param list<int>|null                                  $hospitalIds null rebuilds every hospital
     */
    public function rebuild(?callable $onHospital = null, ?array $hospitalIds = null): int;

    public function consume(): void;

    public function maxPartitionBytes(): int;
}
