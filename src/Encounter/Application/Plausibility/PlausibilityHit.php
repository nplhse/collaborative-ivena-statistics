<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Domain\Enum\PlausibilityCellSource;

/**
 * Result of a lookup in the plausibility table (ADR 033): the bonus (0 … +3), the cell level and source it came from
 * and the snapshot that was read (null without an active snapshot).
 *
 * @psalm-suppress PossiblyUnusedProperty Level and snapshot are recorded per pair by matcher 1.2.0 (#1154).
 */
final readonly class PlausibilityHit
{
    public function __construct(
        public int $points,
        public PlausibilityLevel $level,
        public ?int $snapshotId,
        public ?PlausibilityCellSource $source = null,
    ) {
    }

    public static function none(?int $snapshotId): self
    {
        return new self(0, PlausibilityLevel::None, $snapshotId);
    }
}
