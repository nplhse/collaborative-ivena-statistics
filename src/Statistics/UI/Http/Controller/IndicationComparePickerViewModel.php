<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class IndicationComparePickerViewModel
{
    /**
     * @param list<array{id: int, label: string}> $menuItems
     */
    public function __construct(
        public string $selectedLabelA,
        public string $selectedLabelB,
        public array $menuItems,
        public string $compareUrl,
        public string $compareBaseUrl,
    ) {
    }
}
