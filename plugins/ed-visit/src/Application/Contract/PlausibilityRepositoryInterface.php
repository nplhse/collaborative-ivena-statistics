<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Contract;

use Cois\Plugin\EdVisit\Domain\Entity\PlausibilityProblemArea;
use Cois\Plugin\EdVisit\Domain\Entity\PlausibilitySnapshot;

/**
 * Lookups over the curated problem areas and the snapshots of the plausibility table (ADR 033).
 */
interface PlausibilityRepositoryInterface
{
    /**
     * @return list<PlausibilityProblemArea> ordered by German name
     */
    public function areas(): array;

    public function findAreaByCode(string $code): ?PlausibilityProblemArea;

    public function areaOfPzc(int $pzc): ?PlausibilityProblemArea;

    /**
     * @return list<PlausibilitySnapshot> newest first
     */
    public function snapshots(): array;

    public function activeSnapshot(): ?PlausibilitySnapshot;

    /**
     * The newest draft built from the curated areas, which editing the areas changes.
     */
    public function seedDraft(): ?PlausibilitySnapshot;
}
