<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final readonly class TopListRankedRow
{
    public function __construct(
        public string $identity,
        public string $label,
        public int $count,
        public float $share,
        public int $rank,
        public ?int $entityId = null,
    ) {
    }
}
