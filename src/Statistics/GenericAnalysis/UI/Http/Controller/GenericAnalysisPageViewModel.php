<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

final readonly class GenericAnalysisPageViewModel
{
    /**
     * @param list<array{key: string, title: string, url: string, active: bool}>                         $presetMenu
     * @param list<array{type: string, label: string, options: list<array{key: string, label: string}>}> $dimensionGroups
     * @param list<array{key: string, value: string}>                                                    $preservedQueryFields
     */
    public function __construct(
        public array $presetMenu,
        public string $selectedPresetLabel,
        public array $dimensionGroups,
        public bool $showRestrictedDimensionsHint,
        public string $customFormAction,
        public array $preservedQueryFields,
        public string $formPrimary,
        public string $formSeries,
        public bool $formIncludeNull,
        public ?string $formReferencePreset,
        public bool $isCustom,
        public ?string $referencePresetTitle,
        public ?string $resetToPresetUrl,
    ) {
    }
}
