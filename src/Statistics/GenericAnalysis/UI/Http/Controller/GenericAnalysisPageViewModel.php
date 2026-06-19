<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

final readonly class GenericAnalysisPageViewModel
{
    /**
     * @param list<array{key: string, title: string, url: string, active: bool}>                                         $presetMenu
     * @param list<array{type: string, label: string, options: list<array{key: string, label: string}>}>                 $dimensionGroups
     * @param list<array{key: string, value: string}>                                                                    $preservedQueryFields
     * @param list<array{key: string, label: string, allowed: bool, reason: ?string, selected: bool, sortPriority: int}> $availableMetrics
     * @param list<array{key: string, label: string, selected: bool}>                                                    $visualMetricOptions
     */
    public function __construct(
        public array $presetMenu,
        public string $selectedPresetLabel,
        public array $dimensionGroups,
        public bool $showRestrictedDimensionsHint,
        public string $formAction,
        public array $preservedQueryFields,
        public string $formPrimary,
        public string $formSeries,
        public string $formVisualMetric,
        public bool $formIncludeNull,
        public ?string $formReferencePreset,
        public bool $isCustom,
        public ?string $referencePresetTitle,
        public ?string $resetToPresetUrl,
        public array $availableMetrics = [],
        public array $visualMetricOptions = [],
        public string $saveTitleDraft = '',
        public string $formSeriesMode = 'by_dimension',
        public string $formChartType = '',
        public string $formDisplayMode = 'chart',
        /** @var list<array{value: string, label: string}> */
        public array $seriesModeOptions = [],
        /** @var list<array{value: string, label: string}> */
        public array $chartTypeOptions = [],
        /** @var list<array{value: string, label: string}> */
        public array $displayModeOptions = [],
        public string $defaultBaseMetricKey = 'count',
        public string $formDataSource = 'allocations',
        public string $formHospitalPopulation = 'all',
        public bool $showDataSourceSelector = false,
        public bool $showHospitalPopulationControl = false,
        public bool $showPeriodAppliesHint = false,
        /** @var list<array{value: string, label: string, selected: bool, url?: string}> */
        public array $dataSourceOptions = [],
        /** @var list<array{key: string, label: string, url: string, active: bool, testId: string}> */
        public array $dataSourceHeaderTabs = [],
        /** @var list<array{value: string, label: string, selected: bool, url: string}> */
        public array $hospitalPopulationOptions = [],
        public ?string $launchFormAction = null,
    ) {
    }
}
