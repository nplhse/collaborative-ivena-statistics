<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Contract;

use Cois\Plugin\EdVisit\Application\Plausibility\PlausibilityTable;

/**
 * Reads snapshot cells of the plausibility table (ADR 033). Counts per cell are read only to decide whether an exact
 * cell has enough observations; they never leave PlausibilityTable.
 */
interface PlausibilityTableReaderInterface
{
    public function activeSnapshotId(): ?int;

    public function table(int $snapshotId, int $minObservations): PlausibilityTable;
}
