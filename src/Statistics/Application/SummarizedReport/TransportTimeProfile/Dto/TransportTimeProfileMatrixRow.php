<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto;

final readonly class TransportTimeProfileMatrixRow
{
    /**
     * @param array<string, TransportTimeProfileCell> $cells
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $kind,
        public array $cells,
        public ?float $overallPercent = null,
    ) {
    }
}
