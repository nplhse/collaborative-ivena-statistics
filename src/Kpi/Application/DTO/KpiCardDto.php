<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

use Symfony\Component\Translation\TranslatableMessage;

final readonly class KpiCardDto
{
    /**
     * @param int|float|null $value null when the period has no data
     */
    public function __construct(
        public TranslatableMessage $label,
        public int|float|null $value,
        public ?string $detailUrl,
        public string $icon,
        public bool $isPercent = false,
        public ?TranslatableMessage $detail = null,
        public ?KpiChangeDto $change = null,
    ) {
    }
}
