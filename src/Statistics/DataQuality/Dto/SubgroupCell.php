<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

final readonly class SubgroupCell
{
    public function __construct(
        public string $cellKey,
        public int $populationCount,
        public int $participantCount,
        public bool $supported,
    ) {
    }
}
