<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;

final readonly class ExplorerAnalysisFilterExpander
{
    public function __construct(
        private IndicationGroupRepository $indicationGroupRepository,
    ) {
    }

    /**
     * @param list<AnalysisFilter> $filters
     *
     * @return list<AnalysisFilter>
     */
    public function expand(array $filters): array
    {
        $expanded = [];
        foreach ($filters as $filter) {
            if ('indication_group' !== $filter->dimensionKey) {
                $expanded[] = $filter;

                continue;
            }

            $groupId = $this->intValue($filter->value);
            if (null === $groupId) {
                continue;
            }

            $memberIds = $this->indicationGroupRepository->getIndicationIds($groupId);
            if ([] === $memberIds) {
                $expanded[] = new AnalysisFilter('indication', AnalysisFilterOperator::In, [-1]);

                continue;
            }

            $expanded[] = new AnalysisFilter('indication', AnalysisFilterOperator::In, $memberIds);
        }

        return $expanded;
    }

    /**
     * @param int|string|bool|list<int|string|bool> $value
     */
    private function intValue(int|string|bool|array $value): ?int
    {
        if (\is_array($value)) {
            return null;
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (\is_int($value)) {
            return $value;
        }

        if ('' === $value || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
