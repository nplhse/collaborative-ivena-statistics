<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maps Overview/statistics drawer query parameters onto Explorer analysis filters.
 */
final readonly class ExplorerRequestAnalysisFilterOverlay
{
    /**
     * @return list<array{dimensionKey: string, operator: string, value: mixed}>
     */
    public function toStateFilters(Request $request): array
    {
        $filters = [];

        foreach (StatisticsQueryKeys::DRAWER_FILTERS as $queryKey) {
            $raw = $request->query->get($queryKey);
            if (null === $raw || '' === $raw) {
                continue;
            }

            $mapped = $this->mapDrawerKey((string) $queryKey, $raw);
            if (null === $mapped) {
                continue;
            }

            $filters[] = [
                'dimensionKey' => $mapped->dimensionKey,
                'operator' => $mapped->operator->value,
                'value' => $mapped->value,
            ];
        }

        $indication = $request->query->get('indication');
        if (null !== $indication && '' !== $indication && ctype_digit((string) $indication)) {
            $filters[] = [
                'dimensionKey' => 'indication',
                'operator' => AnalysisFilterOperator::Equals->value,
                'value' => (int) $indication,
            ];
        }

        return $filters;
    }

    private function mapDrawerKey(string $queryKey, mixed $raw): ?AnalysisFilter
    {
        return match ($queryKey) {
            'urgency' => ctype_digit((string) $raw)
                ? new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, (int) $raw)
                : null,
            'age_group' => new AnalysisFilter('age_group', AnalysisFilterOperator::Equals, (string) $raw),
            'gender' => ctype_digit((string) $raw)
                ? new AnalysisFilter('gender', AnalysisFilterOperator::Equals, (int) $raw)
                : null,
            'department' => ctype_digit((string) $raw)
                ? new AnalysisFilter('department', AnalysisFilterOperator::Equals, (int) $raw)
                : null,
            'speciality' => ctype_digit((string) $raw)
                ? new AnalysisFilter('speciality', AnalysisFilterOperator::Equals, (int) $raw)
                : null,
            'infection' => ctype_digit((string) $raw)
                ? new AnalysisFilter('infection', AnalysisFilterOperator::Equals, (int) $raw)
                : null,
            'requiresResus' => $this->booleanFilter('resus', $raw),
            'requiresCathlab' => $this->booleanFilter('cathlab', $raw),
            'isVentilated' => $this->booleanFilter('ventilation', $raw),
            'isShock' => $this->booleanFilter('shock', $raw),
            'isCPR' => $this->booleanFilter('cpr', $raw),
            'isPregnant', 'isWorkAccident', 'isInfectious' => null,
            default => null,
        };
    }

    private function booleanFilter(string $dimensionKey, mixed $raw): ?AnalysisFilter
    {
        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (null === $parsed) {
            if ('1' === (string) $raw || '0' === (string) $raw) {
                $parsed = '1' === (string) $raw;
            } else {
                return null;
            }
        }

        return new AnalysisFilter($dimensionKey, AnalysisFilterOperator::Equals, $parsed ? 1 : 0);
    }
}
