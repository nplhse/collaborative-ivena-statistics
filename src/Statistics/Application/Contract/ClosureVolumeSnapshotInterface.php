<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

/**
 * Named copies of closure_volume_hour to verify that a refactoring keeps or explains its results.
 * Snapshots taken before ADR 015 (urgency × stratum) are compared on department-level urgency
 * metrics closed by the event, the rows both models share.
 *
 * @psalm-type SnapshotSummary = array{name: string, rows: int}
 * @psalm-type SnapshotComparison = array{
 *     legacy: bool,
 *     snapshot_rows: int,
 *     current_rows: int,
 *     only_in_snapshot: int,
 *     only_in_current: int,
 *     changed_rows: int,
 *     changed_columns: array<string, int>,
 *     largest_differences: list<array<string, scalar|null>>
 * }
 */
interface ClosureVolumeSnapshotInterface
{
    /**
     * @return int stored rows
     */
    public function save(string $name, ?int $hospitalId = null): int;

    /**
     * @return SnapshotComparison
     */
    public function compare(string $name, ?int $hospitalId = null, float $tolerance = 0.0001): array;

    public function drop(string $name): void;

    /**
     * @return list<SnapshotSummary>
     */
    public function list(): array;
}
