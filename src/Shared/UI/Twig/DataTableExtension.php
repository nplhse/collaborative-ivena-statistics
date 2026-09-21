<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\UI\Twig\DataTable\BadgePalette;
use App\Shared\UI\Twig\DataTable\BadgeView;

final readonly class DataTableExtension
{
    public function __construct(
        private BadgePalette $badgePalette,
    ) {
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'data_table_badge')]
    public function badge(?string $palette, mixed $value): BadgeView
    {
        return $this->badgePalette->resolve($palette, $value);
    }
}
