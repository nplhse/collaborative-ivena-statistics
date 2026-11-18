<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Database;

use App\Shared\Application\Database\DbalManagedTablesInterface;

/**
 * Closure analysis tables of Statistics, written and read with DBAL by the closure projections
 * (ADR 015); no entity maps them (#1372).
 *
 * @psalm-suppress UnusedClass Tagged via DbalManagedTablesInterface.
 */
final readonly class ClosureAnalysisDbalTables implements DbalManagedTablesInterface
{
    #[\Override]
    public function tables(): array
    {
        return [
            'closure_analysis_care_level',
            'closure_analysis_interval',
            'closure_analysis_source',
            'closure_event',
            'closure_rebuild_request',
            'closure_relation_candidate',
            'closure_volume_hour',
        ];
    }
}
