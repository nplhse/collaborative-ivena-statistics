<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\DTO\DatabaseTableSize;

/**
 * The largest tables of the application database, read live for the Disk Usage page (ADR 017, "Usage metric").
 * The result is not stored with the measurement.
 */
interface DatabaseTableSizeReaderInterface
{
    /**
     * Largest tables first, including their indexes and TOAST data.
     *
     * @param positive-int $limit
     *
     * @return list<DatabaseTableSize>
     *
     * @throws \RuntimeException when the sizes cannot be determined
     */
    public function largestTables(int $limit): array;
}
