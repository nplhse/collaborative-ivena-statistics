<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

use App\Research\Domain\Entity\DataRequestQueryVersion;

/**
 * Runs the query versions of a release in one read-only transaction and writes one CSV file per query into a
 * directory (ADR 045).
 */
interface ExportRunnerInterface
{
    /**
     * @param list<DataRequestQueryVersion> $versions
     */
    public function run(array $versions, string $directory): ExportOutcome;
}
