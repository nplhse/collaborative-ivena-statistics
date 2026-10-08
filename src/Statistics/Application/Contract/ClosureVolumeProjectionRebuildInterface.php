<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface ClosureVolumeProjectionRebuildInterface
{
    /**
     * @param callable(int $processed, int $total): void|null $onHospital
     */
    public function rebuild(?callable $onHospital = null): int;

    public function consume(): void;

    public function maxPartitionBytes(): int;
}
