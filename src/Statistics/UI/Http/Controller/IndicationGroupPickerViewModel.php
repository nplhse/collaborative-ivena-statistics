<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class IndicationGroupPickerViewModel
{
    /**
     * @param list<array{id: int, label: string, url: string, active: bool}> $menuItems
     */
    public function __construct(
        public string $selectedLabel,
        public array $menuItems,
    ) {
    }
}
