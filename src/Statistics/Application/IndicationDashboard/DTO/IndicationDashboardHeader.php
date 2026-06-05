<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

final readonly class IndicationDashboardHeader
{
    public function __construct(
        public int $indicationId,
        public string $indicationName,
        public ?int $indicationCode,
        public int $caseCount,
    ) {
    }
}
