<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Domain\Enum\PlausibilityCellSource;

/**
 * Points of one cell in a reviewed snapshot and in the active snapshot (ADR 033, Privacy: points, source and change
 * only, never counts). A null value means the snapshot has no such cell.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the snapshot review template.
 */
final readonly class PlausibilityCellChange
{
    public function __construct(
        public string $diagramCode,
        public ?int $points,
        public ?int $before,
        public ?PlausibilityCellSource $source,
        public ?int $pzc = null,
    ) {
    }

    public function isChanged(): bool
    {
        return $this->points !== $this->before;
    }
}
