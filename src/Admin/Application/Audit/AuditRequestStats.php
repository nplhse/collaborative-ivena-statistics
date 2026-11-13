<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Facts about all entries of one request id, not only those on the current page.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the audit timeline templates.
 */
final readonly class AuditRequestStats
{
    public function __construct(
        /** All entries with this request id, regardless of filters. */
        public int $total,
        /** Entries with this request id that match the current filter. */
        public int $matching,
        public int $distinctActors,
        public int $distinctOrigins,
    ) {
    }
}
