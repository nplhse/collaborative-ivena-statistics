<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

/**
 * The plausibility table one matcher run reads (ADR 033): the cells of one snapshot, usually the active one, and the
 * rule that resolves a stored MTS diagram code to its canonical code (ADR 032). Without a table the bonus is 0 and no
 * snapshot is recorded. Pure once built, so the matcher stays testable without a database.
 */
final readonly class PlausibilityBonus
{
    /**
     * @param \Closure(string): string $canonicalDiagram stored diagram code => canonical code
     */
    public function __construct(
        private ?PlausibilityTable $table,
        private \Closure $canonicalDiagram,
    ) {
    }

    /**
     * No snapshot: the bonus is 0 for every pair.
     */
    public static function none(): self
    {
        return new self(null, static fn (string $code): string => $code);
    }

    public function snapshotId(): ?int
    {
        return $this->table?->snapshotId;
    }

    public function lookup(?int $pzc, ?string $diagramCode): PlausibilityHit
    {
        if (!$this->table instanceof PlausibilityTable) {
            return PlausibilityHit::none(null);
        }

        $canonical = null === $diagramCode || '' === $diagramCode ? null : ($this->canonicalDiagram)($diagramCode);

        return $this->table->lookup($pzc, $canonical);
    }
}
