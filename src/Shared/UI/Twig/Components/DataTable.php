<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\UI\Twig\DataTable\BadgePalette;
use App\Shared\UI\Twig\DataTable\BadgeView;
use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableValueResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Pagination\PaginationInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'DataTable', template: '@Shared/components/DataTable.html.twig')]
final class DataTable
{
    /** @var list<string> */
    public const array DROP_QUERY_KEYS = ['page', 'cursor', 'after', 'before'];

    /** @var list<int> */
    public const array PAGE_SIZES = [25, 50, 100];

    /** @psalm-suppress PossiblyUnusedProperty Consumed by DataTable.html.twig. */
    public ?string $title = null;

    /** @var PaginationInterface<mixed>|CursorPaginator|null */
    public PaginationInterface|CursorPaginator|null $paginator = null;

    public ?string $paginationRoute = null;

    public bool $showPaginationFooter = true;

    /**
     * @psalm-suppress PossiblyUnusedProperty Consumed by DataTable.html.twig.
     *
     * @var list<array{path?: string, name?: string, active?: bool}>|null
     */
    public ?array $tabs = null;

    /**
     * @var iterable<mixed>|null
     */
    public mixed $rows = null;

    /**
     * @var list<DataTableColumn|array<string, mixed>>|null
     */
    public ?array $columns = null;

    public ?string $sortBy = null;

    public ?string $orderBy = null;

    public string $pageParam = 'page';

    public string $limitParam = 'limit';

    public string $sortByParam = 'sortBy';

    public string $orderByParam = 'orderBy';

    public bool $columnVisibilityEnabled = false;

    public string $columnVisibilityParam = 'columns';

    public bool $columnOrderingEnabled = false;

    public string $columnOrderParam = 'columnOrder';

    /** @var list<string>|null */
    public ?array $visibleColumnKeys = null;

    /** @var list<string>|null */
    public ?array $columnOrder = null;

    public ?string $preferenceKey = null;

    public ?string $preferenceSaveUrl = null;

    public ?string $preferenceCsrfToken = null;

    public ?string $preferenceReturnUrl = null;

    public bool $persistSort = false;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by DataTable.html.twig. */
    public bool $loading = false;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by data_table/_table.html.twig. */
    public ?string $emptyTitle = null;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by data_table/_table.html.twig. */
    public ?string $emptyDescription = null;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by data_table/_table.html.twig. */
    public ?string $emptyIcon = null;

    public ?string $rowClassProperty = null;

    public ?string $rowClass = null;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by data_table/_table.html.twig. */
    public ?string $rowTestId = null;

    /**
     * Extra variables passed into custom cell templates.
     *
     * @var array<string, mixed>
     */
    public array $cellContext = [];

    public ?string $turboFrame = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly DataTableValueResolver $valueResolver,
        private readonly BadgePalette $badgePalette,
    ) {
    }

    public function isDeclarative(): bool
    {
        return [] !== $this->getVisibleColumns();
    }

    /**
     * @return list<DataTableColumn>
     */
    public function getVisibleColumns(): array
    {
        $visible = [];
        $selected = $this->visibleColumnKeys ?? $this->selectedColumnKeys();
        foreach ($this->orderedColumns() as $column) {
            if ($column->required
                || (!$column->configurable && $column->visible)
                || ($column->configurable && (null === $selected ? $column->visible : \in_array($column->key, $selected, true)))
            ) {
                $visible[] = $column;
            }
        }

        return $visible;
    }

    /**
     * @return list<array{column: DataTableColumn, visible: bool, url: string, moveUpUrl: string, moveDownUrl: string, canMoveUp: bool, canMoveDown: bool}>
     */
    public function getColumnVisibilityChoices(): array
    {
        if (!$this->columnVisibilityEnabled) {
            return [];
        }

        $visibleKeys = array_map(
            static fn (DataTableColumn $column): string => $column->key,
            $this->getVisibleColumns(),
        );
        $choices = [];
        $configurableColumns = array_values(array_filter(
            $this->orderedColumns(),
            static fn (DataTableColumn $column): bool => $column->configurable || $column->required,
        ));
        foreach ($configurableColumns as $index => $column) {
            if (!$column->configurable && !$column->required) {
                continue;
            }

            $choices[] = [
                'column' => $column,
                'visible' => \in_array($column->key, $visibleKeys, true),
                'url' => $column->required ? '#' : $this->columnVisibilityUrl($column),
                'moveUpUrl' => $this->columnMoveUrl($column, -1),
                'moveDownUrl' => $this->columnMoveUrl($column, 1),
                'canMoveUp' => $this->columnOrderingEnabled && $index > 0,
                'canMoveDown' => $this->columnOrderingEnabled && $index < \count($configurableColumns) - 1,
            ];
        }

        return $choices;
    }

    public function getShouldShowColumnVisibility(): bool
    {
        return [] !== $this->getColumnVisibilityChoices();
    }

    public function getShouldShowHeaderActions(): bool
    {
        return $this->getShouldShowColumnVisibility()
            || $this->getShouldShowSortMenu();
    }

    public function getShouldShowSortMenu(): bool
    {
        return $this->columnVisibilityEnabled && [] !== $this->getSortChoices();
    }

    /**
     * @return list<array{column: DataTableColumn, sortKey: string, selected: bool}>
     */
    public function getSortChoices(): array
    {
        $choices = [];
        foreach ($this->orderedColumns() as $column) {
            if (!$column->sortable) {
                continue;
            }

            $choices[] = [
                'column' => $column,
                'sortKey' => $column->resolvedSortKey(),
                'selected' => $this->isSortedBy($column),
            ];
        }

        return $choices;
    }

    public function getSelectedSortDirection(): string
    {
        return 'asc' === $this->orderBy ? 'asc' : 'desc';
    }

    /**
     * @return list<int>
     */
    public function getPageSizes(): array
    {
        return self::PAGE_SIZES;
    }

    /**
     * @return array<string, string>
     */
    public function getTurboPaginationLinkAttributes(): array
    {
        if (null === $this->turboFrame || '' === $this->turboFrame) {
            return [];
        }

        return ['data-turbo-frame' => $this->turboFrame];
    }

    public function getSortFormAction(): string
    {
        $route = $this->getResolvedPaginationRoute();
        if (null === $route) {
            return '#';
        }

        $request = $this->currentRequest();
        $routeParams = $request instanceof Request ? $request->attributes->get('_route_params', []) : [];
        if (!\is_array($routeParams)) {
            $routeParams = [];
        }

        return $this->urlGenerator->generate($route, $routeParams);
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    public function getSortFormHiddenFields(): array
    {
        $request = $this->currentRequest();
        $query = $request instanceof Request ? $request->query->all() : [];
        foreach ([$this->sortByParam, $this->orderByParam, $this->limitParam, $this->pageParam, ...self::DROP_QUERY_KEYS] as $key) {
            unset($query[$key]);
        }

        return $this->flattenQueryFields($query);
    }

    public function sortResetUrl(): string
    {
        return $this->buildUrl([
            $this->sortByParam => null,
            $this->orderByParam => null,
            $this->limitParam => null,
        ], dropPagination: true);
    }

    public function columnResetUrl(): string
    {
        return $this->buildUrl([
            $this->columnVisibilityParam => null,
            $this->columnOrderParam => null,
        ], dropPagination: true);
    }

    public function isPreferenceEnabled(): bool
    {
        return null !== $this->preferenceKey
            && '' !== $this->preferenceKey
            && null !== $this->preferenceSaveUrl
            && '' !== $this->preferenceSaveUrl
            && null !== $this->preferenceCsrfToken;
    }

    /**
     * @return list<string>
     */
    public function getResolvedColumnOrder(): array
    {
        return array_map(
            static fn (DataTableColumn $column): string => $column->key,
            $this->orderedColumns(),
        );
    }

    /**
     * @return list<mixed>
     */
    public function getRowItems(): array
    {
        if (null !== $this->rows) {
            return $this->iterableToList($this->rows);
        }

        if ($this->paginator instanceof PaginationInterface) {
            return $this->paginator->getItems();
        }

        if ($this->paginator instanceof CursorPaginator) {
            return $this->iterableToList($this->paginator->getResults());
        }

        return [];
    }

    public function hasRows(): bool
    {
        return [] !== $this->getRowItems();
    }

    public function isCursorPaginator(): bool
    {
        return $this->paginator instanceof CursorPaginator;
    }

    public function isUxPaginator(): bool
    {
        return $this->paginator instanceof PaginationInterface;
    }

    public function getPageSize(): int
    {
        $paginator = $this->paginator;
        if ($paginator instanceof PaginationInterface) {
            return $paginator->getItemsPerPage();
        }

        if ($paginator instanceof CursorPaginator) {
            return $paginator->getPageSize();
        }

        return 25;
    }

    public function getShouldShowFooter(): bool
    {
        return $this->showPaginationFooter && null !== $this->paginator && $this->hasRows();
    }

    /**
     * @param object|array<string, mixed> $row
     */
    public function cellValue(object|array $row, DataTableColumn $column): mixed
    {
        return $this->valueResolver->resolve($row, $column);
    }

    /**
     * @param object|array<string, mixed> $row
     */
    public function badgeView(object|array $row, DataTableColumn $column): BadgeView
    {
        $palette = $column->option('badgePalette');
        $paletteName = \is_string($palette) ? $palette : null;

        return $this->badgePalette->resolve($paletteName, $this->valueResolver->enumValue($this->cellValue($row, $column)));
    }

    /**
     * @param object|array<string, mixed> $row
     */
    public function badgeNumber(object|array $row, DataTableColumn $column): mixed
    {
        $property = $column->option('numberProperty');
        if (!\is_string($property) || '' === $property) {
            return null;
        }

        return $this->valueResolver->read($row, $property);
    }

    /**
     * @param object|array<string, mixed> $row
     */
    public function cellHref(object|array $row, DataTableColumn $column): ?string
    {
        $route = $column->option('route');
        if (!\is_string($route) || '' === $route) {
            return null;
        }

        $params = $column->option('routeParams', []);
        if (!\is_array($params)) {
            $params = [];
        }

        $resolved = [];
        foreach ($params as $name => $property) {
            if (!\is_string($name) || !\is_string($property)) {
                continue;
            }
            $scalar = $this->valueResolver->scalar($this->valueResolver->read($row, $property));
            if (null === $scalar || '' === $scalar) {
                return null;
            }
            $resolved[$name] = $scalar;
        }

        return $this->urlGenerator->generate($route, $resolved);
    }

    /**
     * @param object|array<string, mixed> $row
     */
    public function rowCssClass(object|array $row): string
    {
        if (null === $this->rowClassProperty || null === $this->rowClass) {
            return '';
        }

        $value = $this->valueResolver->read($row, $this->rowClassProperty);

        return $value ? $this->rowClass : '';
    }

    public function sortUrl(DataTableColumn $column): string
    {
        $sortKey = $column->resolvedSortKey();
        $nextOrder = $this->sortBy === $sortKey && 'asc' === $this->orderBy ? 'desc' : 'asc';

        return $this->sortUrlFor($column, $nextOrder);
    }

    private function sortUrlFor(DataTableColumn $column, string $order): string
    {
        return $this->buildUrl([
            $this->sortByParam => $column->resolvedSortKey(),
            $this->orderByParam => $order,
        ], dropPagination: true);
    }

    public function isSortedBy(DataTableColumn $column): bool
    {
        return $this->sortBy === $column->resolvedSortKey();
    }

    public function sortDirection(DataTableColumn $column): ?string
    {
        if (!$this->isSortedBy($column)) {
            return null;
        }

        return 'desc' === $this->orderBy ? 'descending' : 'ascending';
    }

    /**
     * @return list<array{size: int, url: string, active: bool}>
     */
    public function getPageSizeLinks(): array
    {
        $pageSize = $this->getPageSize();
        $links = [];
        foreach (self::PAGE_SIZES as $size) {
            $params = [$this->limitParam => $size];
            if ($this->isCursorPaginator()) {
                $params['cursor'] = null;
            } else {
                $params[$this->pageParam] = 1;
            }

            $links[] = [
                'size' => $size,
                'url' => $this->buildUrl($params, dropPagination: $this->isCursorPaginator()),
                'active' => $size === $pageSize,
            ];
        }

        return $links;
    }

    public function cursorPreviousUrl(): ?string
    {
        $request = $this->currentRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $cursor = $request->query->get('cursor');
        if (!\is_string($cursor) || '' === $cursor) {
            return null;
        }

        return $this->buildUrl(['cursor' => null], dropPagination: true);
    }

    public function cursorNextUrl(): ?string
    {
        if (!$this->paginator instanceof CursorPaginator || !$this->paginator->hasNextPage()) {
            return null;
        }

        $cursor = $this->paginator->getNextCursor();
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        return $this->buildUrl(['cursor' => $cursor], dropPagination: false);
    }

    public function getResolvedPaginationRoute(): ?string
    {
        if (null !== $this->paginationRoute && '' !== $this->paginationRoute) {
            return $this->paginationRoute;
        }

        $request = $this->currentRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $route = $request->attributes->get('_route');

        return \is_string($route) && '' !== $route ? $route : null;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildUrl(array $overrides, bool $dropPagination): string
    {
        $route = $this->getResolvedPaginationRoute();
        if (null === $route) {
            return '#';
        }

        $request = $this->currentRequest();
        $query = $request instanceof Request ? $request->query->all() : [];
        $routeParams = $request instanceof Request ? $request->attributes->get('_route_params', []) : [];
        if (!\is_array($routeParams)) {
            $routeParams = [];
        }

        if ($dropPagination) {
            foreach ([$this->pageParam, ...self::DROP_QUERY_KEYS] as $key) {
                unset($query[$key]);
            }
        }

        $parameters = array_merge($routeParams, $query, $overrides);
        foreach ($parameters as $key => $value) {
            if (null === $value) {
                unset($parameters[$key]);
            }
        }

        return $this->urlGenerator->generate($route, $parameters);
    }

    private function currentRequest(): ?Request
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request instanceof Request ? $request : null;
    }

    /**
     * @return list<string>|null
     */
    private function selectedColumnKeys(): ?array
    {
        if (!$this->columnVisibilityEnabled) {
            return null;
        }

        $request = $this->currentRequest();
        if (!$request instanceof Request || !$request->query->has($this->columnVisibilityParam)) {
            return null;
        }

        $query = $request->query->all();
        $value = $query[$this->columnVisibilityParam] ?? '';
        if (!\is_string($value)) {
            return [];
        }

        $available = [];
        foreach ($this->normalizedColumns() as $column) {
            if ($column->configurable) {
                $available[$column->key] = true;
            }
        }

        $selected = [];
        foreach (explode(',', $value) as $key) {
            $key = trim($key);
            if (isset($available[$key]) && !\in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    /**
     * @return list<string>|null
     */
    private function requestedColumnOrder(): ?array
    {
        if (!$this->columnOrderingEnabled) {
            return null;
        }
        if (null !== $this->columnOrder) {
            return $this->columnOrder;
        }

        $request = $this->currentRequest();
        if (!$request instanceof Request || !$request->query->has($this->columnOrderParam)) {
            return null;
        }

        $query = $request->query->all();
        $value = $query[$this->columnOrderParam] ?? '';
        if (!\is_string($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $key): bool => '' !== $key,
        )));
    }

    /**
     * @return list<DataTableColumn>
     */
    private function orderedColumns(): array
    {
        $columns = $this->normalizedColumns();
        $requested = $this->requestedColumnOrder();
        if (null === $requested) {
            return $columns;
        }

        $byKey = [];
        foreach ($columns as $column) {
            $byKey[$column->key] = $column;
        }

        $ordered = [];
        foreach ([...$requested, ...array_keys($byKey)] as $key) {
            if (isset($byKey[$key]) && !isset($ordered[$key])) {
                $ordered[$key] = $byKey[$key];
            }
        }

        return array_values($ordered);
    }

    private function columnVisibilityUrl(DataTableColumn $toggled): string
    {
        $selected = $this->selectedColumnKeys();
        if (null === $selected) {
            $selected = [];
            foreach ($this->normalizedColumns() as $column) {
                if ($column->configurable && $column->visible) {
                    $selected[] = $column->key;
                }
            }
        }

        $position = array_search($toggled->key, $selected, true);
        if (false === $position) {
            $selected[] = $toggled->key;
        } else {
            unset($selected[$position]);
        }

        $ordered = [];
        foreach ($this->orderedColumns() as $column) {
            if (\in_array($column->key, $selected, true)) {
                $ordered[] = $column->key;
            }
        }

        return $this->buildUrl([
            $this->columnVisibilityParam => implode(',', $ordered),
        ], dropPagination: true);
    }

    private function columnMoveUrl(DataTableColumn $column, int $offset): string
    {
        if (!$this->columnOrderingEnabled) {
            return '#';
        }

        $order = array_map(
            static fn (DataTableColumn $item): string => $item->key,
            $this->orderedColumns(),
        );
        $index = array_search($column->key, $order, true);
        if (false === $index) {
            return '#';
        }

        $target = $index + $offset;
        if ($target < 0 || $target >= \count($order)) {
            return '#';
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        return $this->buildUrl([
            $this->columnOrderParam => implode(',', $order),
        ], dropPagination: true);
    }

    /**
     * @return list<DataTableColumn>
     */
    private function normalizedColumns(): array
    {
        if (null === $this->columns) {
            return [];
        }

        $normalized = [];
        foreach ($this->columns as $column) {
            $normalized[] = DataTableColumn::fromMixed($column);
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return list<array{name: string, value: string}>
     */
    private function flattenQueryFields(array $query, string $prefix = ''): array
    {
        $fields = [];
        foreach ($query as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix.'['.$key.']';
            if (\is_array($value)) {
                $fields = [...$fields, ...$this->flattenQueryFields($value, $name)];
                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }

            $fields[] = ['name' => $name, 'value' => (string) $value];
        }

        return $fields;
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return list<mixed>
     */
    private function iterableToList(iterable $items): array
    {
        if (\is_array($items)) {
            return array_values($items);
        }

        return array_values(iterator_to_array($items, false));
    }
}
