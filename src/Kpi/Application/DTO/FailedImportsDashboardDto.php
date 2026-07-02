<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

final readonly class FailedImportsDashboardDto
{
    /**
     * @param list<FailedImportRowDto> $rows
     */
    public function __construct(
        public array $rows,
        public int $totalFailedCount,
        public string $allFailedImportsUrl,
    ) {
    }
}
