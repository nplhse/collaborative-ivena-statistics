<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

use App\Research\Domain\Enum\QueryKind;

/**
 * Runs an export query once for the administrator, read-only and with limits (ADR 045).
 */
interface QueryDryRunnerInterface
{
    public function run(string $sql, QueryKind $kind): DryRunResult;
}
