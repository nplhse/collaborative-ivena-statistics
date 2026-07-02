<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

final readonly class OpsCardDto
{
    public function __construct(
        public string $labelKey,
        public string $value,
        public string $icon,
        public ?string $detailUrl = null,
        public string $style = 'primary',
        public ?int $trendBytes = null,
    ) {
    }
}
