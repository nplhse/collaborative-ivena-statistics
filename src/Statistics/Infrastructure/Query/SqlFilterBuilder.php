<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Panel\PanelDefinition;

final readonly class SqlFilterBuilder
{
    public function __construct(
        private FilterRegistry $filterRegistry,
    ) {
    }

    /**
     * @return array{where: string, params: array<string, mixed>, types: array<string, mixed>}
     */
    public function buildWhere(FilterState $filterState, PanelDefinition $panel): array
    {
        $parts = [];
        $params = [];
        $types = [];

        foreach ($panel->filters as $filterKey) {
            $definition = $this->filterRegistry->get($filterKey);
            $value = $filterState->get($filterKey);

            if ('date_range' === $definition->type) {
                $column = $definition->field;

                if (\is_string($value) && 'all_cases' === $value) {
                    continue;
                }

                if (\is_string($value) && 'last_12_months' === $value) {
                    $parts[] = $column.' >= :date_from_default';
                    $params['date_from_default'] = new \DateTimeImmutable('-12 months')->format('Y-m-d 00:00:00');
                    continue;
                }

                if (\is_array($value) && isset($value['from'], $value['to'])) {
                    $parts[] = $column.' >= :date_from AND '.$column.' <= :date_to';
                    $params['date_from'] = $value['from'].' 00:00:00';
                    $params['date_to'] = $value['to'].' 23:59:59';
                }

                continue;
            }

            if ('select' === $definition->type) {
                if (!\is_array($value) || [] === $value) {
                    continue;
                }

                $placeholders = [];
                foreach ($value as $idx => $intValue) {
                    $param = $definition->field.'_'.$idx;
                    $placeholders[] = ':'.$param;
                    $params[$param] = (int) $intValue;
                }

                $parts[] = $definition->field.' IN ('.implode(', ', $placeholders).')';
            }
        }

        return [
            'where' => [] === $parts ? '' : ' WHERE '.implode(' AND ', $parts),
            'params' => $params,
            'types' => $types,
        ];
    }
}
