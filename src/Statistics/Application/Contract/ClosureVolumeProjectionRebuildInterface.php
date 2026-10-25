<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface ClosureVolumeProjectionRebuildInterface
{
    /**
     * @param callable(int $processed, int $total, int $pass): void|null $onHospital      pass 2 rebuilds hospitals with late requests
     * @param list<int>|null                                             $hospitalIds     null rebuilds every hospital
     * @param string|null                                                $referenceWindow `symmetric` (configured default) or `backward`, for comparison runs
     */
    public function rebuild(?callable $onHospital = null, ?array $hospitalIds = null, ?string $referenceWindow = null): int;

    /**
     * Rebuilds what open rebuild requests ask for. Without $waitForLock it returns at once while another rebuild runs.
     */
    public function consume(bool $waitForLock = true): void;

    public function maxPartitionBytes(): int;

    /**
     * Seconds and SQL statements per step of the last volume build, slowest first.
     *
     * @return array<string, array{seconds: float, queries: int}>
     */
    public function lastProfile(): array;
}
