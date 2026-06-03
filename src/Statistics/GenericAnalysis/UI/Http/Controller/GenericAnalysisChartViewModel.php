<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisTableRowLimit;

final readonly class GenericAnalysisChartViewModel
{
    /**
     * @param list<string>                              $allowedChartTypes Chart type values (GenericAnalysisChartType::value)
     * @param list<string>                              $warnings
     * @param array<string, array<string, mixed>>       $specsByChartType
     * @param list<array{value: string, label: string}> $chartTypeOptions
     * @param array<int|string, string>                 $rowLimitUrls
     */
    public function __construct(
        public string $title,
        public ?string $subtitle,
        public bool $hasChart,
        public string $defaultChartType,
        public array $allowedChartTypes,
        /** @var array<string, mixed>|null */
        public ?array $initialSpec,
        public array $specsByChartType,
        public ?string $xAxisLabel,
        public ?string $yAxisLabel,
        public bool $isRelative,
        public string $valueLabel,
        public array $warnings,
        public string $emptyStateMessage,
        public array $chartTypeOptions,
        public bool $showChartTypeSelector,
        public bool $exportPlanned,
        public bool $showRowLimitControl = false,
        public GenericAnalysisTableRowLimit $activeRowLimit = GenericAnalysisTableRowLimit::All,
        public array $rowLimitUrls = [],
    ) {
    }
}
