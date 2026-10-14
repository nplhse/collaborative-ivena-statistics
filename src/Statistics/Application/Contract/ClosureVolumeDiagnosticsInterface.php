<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

/**
 * Read-only data checks that precede the closure volume refactoring (ADR 015).
 *
 * @psalm-type ReferenceQualityRow = array{hospital_id: int, scope: string, rows: int, weekday_hour: int, day_time_bucket: int, insufficient: int, events_with_insufficient_closure_rows: int}
 * @psalm-type CoverageRow = array{hospital_id: int, kind: string, derived: bool, ranges: int, first_start: ?string, last_end: ?string, gaps: list<string>}
 * @psalm-type BoundaryRow = array{hospital_id: int, intervals_at_import_end: int, with_continuation: int, at_month_boundary: int, export_boundary_relations: int}
 * @psalm-type ProvisionalRow = array{hospital_id: int, events: int, final: int, awaiting_allocations: int, awaiting_closures: int, incomplete_before: int, not_derived: bool}
 */
interface ClosureVolumeDiagnosticsInterface
{
    /**
     * @return list<ReferenceQualityRow>
     */
    public function referenceQuality(?int $hospitalId): array;

    /**
     * @return list<CoverageRow>
     */
    public function coverage(?int $hospitalId): array;

    /**
     * @return list<BoundaryRow>
     */
    public function exportBoundaries(?int $hospitalId): array;

    /**
     * Events that would be provisional under a symmetric reference window of $weeks.
     *
     * @return list<ProvisionalRow>
     */
    public function provisional(?int $hospitalId, int $weeks): array;
}
