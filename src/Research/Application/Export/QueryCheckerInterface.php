<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

use App\Research\Domain\Enum\QueryKind;

/**
 * Checks an export query against the allowlist on the export connection (ADR 045).
 */
interface QueryCheckerInterface
{
    /**
     * @return list<QueryViolation> empty when the query passes
     */
    public function check(string $sql, QueryKind $kind): array;
}
