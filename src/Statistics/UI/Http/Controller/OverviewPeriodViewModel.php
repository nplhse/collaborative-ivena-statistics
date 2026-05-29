<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

/**
 * Overview-specific period picker and footer navigation state.
 */
final readonly class OverviewPeriodViewModel
{
    /**
     * @param list<array{key: string, label: string, url: string, active: bool}>    $primaryMenu
     * @param list<array{label: string, url: string, active: bool, divider?: bool}> $secondaryMenu
     */
    public function __construct(
        public string $headingLabel,
        public string $primaryDropdownLabel,
        public ?string $secondaryDropdownLabel,
        public bool $showSecondaryPicker,
        public array $primaryMenu,
        public array $secondaryMenu,
        public ?string $previousUrl,
        public ?string $nextUrl,
        public ?string $previousLabel,
        public ?string $nextLabel,
        public bool $previousEnabled,
        public bool $nextEnabled,
        public bool $showNavigation,
    ) {
    }
}
