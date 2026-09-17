<?php

declare(strict_types=1);

namespace App\Statistics\Application\InsightCompare;

use App\Statistics\Application\ComparisonFilterInputFactory;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

final readonly class InsightCompareFilterResolver
{
    public function __construct(
        private ComparisonFilterInputFactory $comparisonFilterInputFactory,
        private StatisticsFilterFactory $statisticsFilterFactory,
        private BenchmarkSelectionFormDataFactory $selectionFormDataFactory,
        private BenchmarkSelectionQueryBuilder $selectionQueryBuilder,
    ) {
    }

    public function resolve(Request $request, ?User $user, StatisticsFilter $primaryFilter): StatisticsFilter
    {
        if (!$this->hasComparisonQuery($request)) {
            return $primaryFilter;
        }

        $input = $this->comparisonFilterInputFactory->fromQuery(
            $this->comparisonQueryBag($request, $primaryFilter),
            $primaryFilter,
            $primaryFilter->cohortType?->value() ?? '',
        );

        return $this->statisticsFilterFactory->createFromInput($input, $user);
    }

    /**
     * Copies the primary filter into empty `comparison_*` keys when `comparison_scope` is absent.
     *
     * @return InputBag<string>
     */
    public function comparisonQueryBag(Request $request, StatisticsFilter $primaryFilter): InputBag
    {
        $queryBag = clone $request->query;
        if ('' !== trim($queryBag->getString(StatisticsQueryKeys::COMPARISON_SCOPE))) {
            return $queryBag;
        }

        foreach ($this->selectionQueryBuilder->sideFilterParams(
            $this->selectionFormDataFactory->fromFilter($primaryFilter),
            true,
        ) as $key => $value) {
            if ('' === trim($queryBag->getString($key))) {
                $queryBag->set($key, (string) $value);
            }
        }

        return $queryBag;
    }

    public function hasComparisonQuery(Request $request): bool
    {
        return array_any(StatisticsQueryKeys::COMPARISON_FILTERS, fn (string $key): bool => '' !== trim($request->query->getString($key)));
    }
}
