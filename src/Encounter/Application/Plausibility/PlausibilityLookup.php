<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Contract\PlausibilityTableReaderInterface;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The plausibility bonus of an allocation's PZC and a visit's MTS diagram from the active snapshot (ADR 033). Without
 * an active snapshot the bonus is 0. The diagram code as stored in the encounter is resolved to its canonical code
 * first (ADR 032); a code the catalog does not know is used as it is.
 *
 * Matcher 1.2.0 reads the active snapshot through {@see self::active()} in pass 2 (#1154); replays may read any
 * snapshot through {@see self::ofSnapshot()}.
 */
final class PlausibilityLookup implements ResetInterface
{
    private ?PlausibilityTable $active = null;

    private bool $loaded = false;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private readonly PlausibilityTableReaderInterface $reader,
        private readonly MtsCatalog $catalog,
        #[Autowire(param: 'app.encounter.plausibility.min_observations')]
        private readonly int $minObservations,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Covered by the plausibility tests; the matcher reads the table through active(). */
    public function bonus(?int $pzc, ?string $diagramCode): PlausibilityHit
    {
        return $this->active()->lookup($pzc, $diagramCode);
    }

    /**
     * The active snapshot for the matcher, or no bonus without one.
     */
    public function active(): PlausibilityBonus
    {
        return new PlausibilityBonus($this->activeTable(), $this->canonicalResolver());
    }

    /**
     * Any snapshot (also a draft or a retired one) for a replay; an unknown id gives an empty table.
     */
    public function ofSnapshot(int $snapshotId): PlausibilityBonus
    {
        return new PlausibilityBonus($this->table($snapshotId), $this->canonicalResolver());
    }

    public function activeTable(): ?PlausibilityTable
    {
        if (!$this->loaded) {
            $snapshotId = $this->reader->activeSnapshotId();
            $this->active = null === $snapshotId ? null : $this->reader->table($snapshotId, $this->minObservations);
            $this->loaded = true;
        }

        return $this->active;
    }

    public function table(int $snapshotId): PlausibilityTable
    {
        return $this->reader->table($snapshotId, $this->minObservations);
    }

    public function canonicalDiagram(?string $diagramCode): ?string
    {
        if (null === $diagramCode || '' === $diagramCode) {
            return null;
        }

        return $this->catalog->resolve(MtsCatalogKind::Diagram, $diagramCode)->code ?? $diagramCode;
    }

    /**
     * @return \Closure(string): string
     */
    private function canonicalResolver(): \Closure
    {
        return fn (string $code): string => $this->canonicalDiagram($code) ?? $code;
    }

    #[\Override]
    public function reset(): void
    {
        $this->active = null;
        $this->loaded = false;
    }
}
