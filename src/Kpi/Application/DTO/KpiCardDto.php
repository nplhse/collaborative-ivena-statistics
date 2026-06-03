<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

final readonly class KpiCardDto
{
    public function __construct(
        public string $labelKey,
        public string $value,
        public ?string $detailUrl,
        public string $icon,
    ) {
    }
}
