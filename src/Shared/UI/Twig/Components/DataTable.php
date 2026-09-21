<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\Shared\UI\Twig\DataTable\BadgePalette;
use App\Shared\UI\Twig\DataTable\BadgeView;
use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableValueResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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

    public Paginator|CursorPaginator|null $paginator = null;

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
        foreach ($this->normalizedColumns() as $column) {
            if ($column->visible) {
                $visible[] = $column;
            }
        }

        return $visible;
    }

    /**
     * @return list<mixed>
     */
    public function getRowItems(): array
    {
        if (null !== $this->rows) {
            return $this->iterableToList($this->rows);
        }

        if (null === $this->paginator) {
            return [];
        }

        return $this->iterableToList($this->paginator->getResults());
    }

    public function hasRows(): bool
    {
        return [] !== $this->getRowItems();
    }

    public function isCursorPaginator(): bool
    {
        return $this->paginator instanceof CursorPaginator;
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

        return $this->buildUrl([
            'sortBy' => $sortKey,
            'orderBy' => $nextOrder,
        ], dropPagination: true);
    }

    public function isSortedBy(DataTableColumn $column): bool
    {
        return $this->sortBy === $column->resolvedSortKey();
    }

    /**
     * @return list<array{size: int, url: string, active: bool}>
     */
    public function getPageSizeLinks(): array
    {
        $pageSize = $this->paginator?->getPageSize() ?? 25;
        $links = [];
        foreach (self::PAGE_SIZES as $size) {
            $params = ['limit' => $size];
            if ($this->isCursorPaginator()) {
                $params['cursor'] = null;
            } else {
                $params['page'] = 1;
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
            foreach (self::DROP_QUERY_KEYS as $key) {
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
