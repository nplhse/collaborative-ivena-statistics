<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

final readonly class BadgeView
{
    public function __construct(
        public string $label,
        public string $cssClass,
        public BadgePresentation $presentation = BadgePresentation::Badge,
        public ?string $statusColor = null,
        public ?string $icon = null,
        public bool $animatedDot = false,
    ) {
    }
}
