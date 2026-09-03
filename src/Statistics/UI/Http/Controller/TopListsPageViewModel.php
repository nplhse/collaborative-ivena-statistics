<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\TopList\TopListArrayPaginator;
use App\Statistics\Application\TopList\TopListDefinitionInterface;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;

final readonly class TopListsPageViewModel
{
    /**
     * @param list<TopListDefinitionInterface>     $topListDefinitions
     * @param array<string, string>                $topListSelectUrls
     * @param array<int|string, string>            $limitUrls
     * @param array<int, string>                   $pageSizeUrls
     * @param array<string, bool|float|int|string> $comparisonPreservedQuery
     */
    public function __construct(
        public ?StatisticWidget $topListWidget,
        public ?TopListComparisonViewModel $comparison,
        public array $topListDefinitions,
        public string $currentTopListKey,
        public array $topListSelectUrls,
        public int|string $currentLimit,
        public array $limitUrls,
        public int $currentPageSize,
        public array $pageSizeUrls,
        public string $headerTitleKey,
        public string $headerSubtitleKey,
        public bool $compareEnabled,
        public string $compareEnableUrl,
        public string $compareDisableUrl,
        public string $compareSwapUrl,
        public string $compareContinueWithBUrl,
        public bool $truncated,
        public ?TopListArrayPaginator $paginator,
        public ?BenchmarkSelectionSideFormData $primaryFormData,
        public ?BenchmarkSelectionSideFormData $comparisonFormData,
        public array $comparisonPreservedQuery,
        public string $indexUrl,
        public ?string $catalogListUrl,
    ) {
    }
}
