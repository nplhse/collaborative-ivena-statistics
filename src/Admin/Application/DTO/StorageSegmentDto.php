<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

final readonly class StorageSegmentDto
{
    public function __construct(
        public string $key,
        public string $labelKey,
        public int $bytes,
        public string $color,
        public string $icon,
    ) {
    }
}
