<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

final readonly class BenchmarkSelectionPickerSideViewModel
{
    /**
     * @param list<array{label: string, active: bool, params: array<string, scalar>}>                 $scopePrimaryMenu
     * @param list<array{label: string, active: bool, params: array<string, scalar>}>                 $scopeSecondaryMenu
     * @param list<array{label: string, active: bool, params: array<string, scalar>}>                 $periodPrimaryMenu
     * @param list<array{divider: true}|array{label: string, active: bool, params: array<string, scalar>}> $periodSecondaryMenu
     */
    public function __construct(
        public bool $showScopeSecondaryPicker,
        public string $scopePrimaryDropdownLabel,
        public ?string $scopeSecondaryDropdownLabel,
        public string $periodPrimaryDropdownLabel,
        public ?string $periodSecondaryDropdownLabel,
        public bool $showPeriodSecondaryPicker,
        public array $scopePrimaryMenu,
        public array $scopeSecondaryMenu,
        public array $periodPrimaryMenu,
        public array $periodSecondaryMenu,
    ) {
    }
}
