<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

final readonly class HealthCheckItemDto
{
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $value,
        public string $severity,
        public string $icon,
    ) {
    }
}
