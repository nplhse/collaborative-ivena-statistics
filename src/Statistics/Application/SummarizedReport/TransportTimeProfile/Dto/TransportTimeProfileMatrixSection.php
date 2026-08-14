<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto;

final readonly class TransportTimeProfileMatrixSection
{
    /**
     * @param list<TransportTimeProfileMatrixRow> $rows
     */
    public function __construct(
        public string $key,
        public string $titleKey,
        public array $rows,
    ) {
    }
}
