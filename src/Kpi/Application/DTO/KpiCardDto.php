<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

use Symfony\Component\Translation\TranslatableMessage;

final readonly class KpiCardDto
{
    public function __construct(
        public TranslatableMessage $label,
        public string $value,
        public ?string $detailUrl,
        public string $icon,
    ) {
    }
}
