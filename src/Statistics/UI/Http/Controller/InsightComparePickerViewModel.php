<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;

final readonly class InsightComparePickerViewModel
{
    /**
     * @param array<string, bool|float|int|string> $preservedQuery
     */
    public function __construct(
        public InsightSubject $subjectA,
        public ?InsightSubject $subjectB,
        public string $searchUrl,
        public string $compareBaseUrl,
        public BenchmarkSelectionSideFormData $comparisonFormData,
        public array $preservedQuery,
        public string $referenceSummaryA,
    ) {
    }
}
