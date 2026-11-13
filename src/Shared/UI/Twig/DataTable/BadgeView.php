<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

use Symfony\Contracts\Translation\TranslatableInterface;

/** @psalm-suppress PossiblyUnusedProperty Consumed by DataTable cell templates. */
final readonly class BadgeView
{
    public function __construct(
        public string|TranslatableInterface $label,
        public string $cssClass,
        public BadgePresentation $presentation = BadgePresentation::Badge,
        public ?string $statusColor = null,
        public ?string $icon = null,
        public bool $animatedDot = false,
    ) {
    }
}
