<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

/**
 * The data state an export is based on (ADR 045): the latest finished import as a readable date, and the imports that
 * were finished when the run started, for the internal record.
 */
interface ExportDataStateInterface
{
    /**
     * @return array{latest_import_at: ?string, imports: int, latest_import_id: ?int}
     */
    public function current(): array;
}
