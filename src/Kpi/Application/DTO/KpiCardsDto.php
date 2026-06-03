<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

final readonly class KpiCardsDto
{
    /**
     * @param list<KpiCardDto> $cards
     */
    public function __construct(
        public array $cards,
    ) {
    }
}
