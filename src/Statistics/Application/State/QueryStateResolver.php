<?php

declare(strict_types=1);

namespace App\Statistics\Application\State;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Panel\PanelDefinition;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class QueryStateResolver
{
    public function __construct(
        private FilterRegistry $filterRegistry,
    ) {
    }

    /**
     * @param InputBag<string> $query
     */
    public function resolveFilters(InputBag $query, PanelDefinition $panel): FilterState
    {
        $resolved = [];
        /** @var array<string, mixed> $f */
        $f = $query->all('f');

        foreach ($panel->filters as $filterKey) {
            $definition = $this->filterRegistry->get($filterKey);
            $rawValue = $f[$filterKey] ?? null;
            $default = $panel->filterDefaults[$filterKey] ?? $definition->defaultValue;
            $resolved[$filterKey] = $this->normalizeFilterValue($definition->type, $rawValue, $default);
        }

        return new FilterState($resolved);
    }

    /**
     * @param InputBag<string> $query
     */
    public function resolveViewMode(InputBag $query, PanelDefinition $panel, bool $showPercent): string
    {
        $mode = $query->getString('view', '');
        if (\in_array($mode, ['absolute', 'percent_of_total', 'grouped', 'stacked', 'percent'], true)) {
            return $mode;
        }

        if ($showPercent) {
            return 'stacked';
        }

        $defaultView = $panel->options['default_view'] ?? 'grouped';

        return \is_string($defaultView) ? $defaultView : 'grouped';
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function serializeToQuery(array $filters, string $viewMode): array
    {
        return [
            'view' => $viewMode,
            'f' => $filters,
        ];
    }

    private function normalizeFilterValue(string $type, mixed $value, mixed $defaultValue): mixed
    {
        if ('date_range' === $type) {
            if (\is_string($value) && \in_array($value, ['all_cases', 'last_12_months'], true)) {
                return $value;
            }

            if (\is_array($value) && isset($value['from'], $value['to'])) {
                return [
                    'from' => (string) $value['from'],
                    'to' => (string) $value['to'],
                ];
            }

            return $defaultValue;
        }

        if ('select' === $type) {
            if (\is_string($value) || is_numeric($value)) {
                $value = [$value];
            }

            if (!\is_array($value)) {
                return $defaultValue;
            }

            $out = [];
            foreach ($value as $v) {
                if (is_numeric($v)) {
                    $out[] = (int) $v;
                }
            }

            return array_values(array_unique($out));
        }

        return $value ?? $defaultValue;
    }
}
