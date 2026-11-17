<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Infrastructure\Import;

use App\Encounter\Contract\Import\SchemaWriteResult;

/**
 * Write result of `ed_visit` with its suspected duplicates (ADR 029). The counter and its report card are
 * specific to `ed_visit` and stay in the core until MTS moves into its bundle (#1300).
 */
final readonly class EdVisitWriteResult extends SchemaWriteResult
{
    /**
     * @param list<string> $insertedDays
     */
    public function __construct(
        int $inserted,
        array $insertedDays,
        public int $suspectedDuplicates,
    ) {
        parent::__construct($inserted, $insertedDays);
    }
}
