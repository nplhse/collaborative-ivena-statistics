<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto;

final readonly class TransportTimeProfileInsight
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
    ) {
    }
}
