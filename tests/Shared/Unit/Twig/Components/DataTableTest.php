<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\UI\Twig\Components\DataTable;
use App\Shared\UI\Twig\DataTable\BadgePalette;
use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableValueResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\UX\Pagination\Test\PaginatorFactory;

final class DataTableTest extends TestCase
{
    public function testHidesInvisibleColumnsAndTogglesSort(): void
    {
        $table = $this->table(Request::create('/explore/state', 'GET', [
            'search' => 'sh',
            'page' => '2',
        ]));
        $table->paginationRoute = 'app_explore_state_list';
        $table->sortBy = 'name';
        $table->orderBy = 'asc';
        $table->columns = [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'hidden', 'label' => 'Hidden', 'visible' => false],
        ];

        $visible = $table->getVisibleColumns();
        self::assertCount(1, $visible);
        self::assertSame('name', $visible[0]->key);

        $url = $table->sortUrl($visible[0]);
        self::assertStringContainsString('/explore/state?', $url);
        self::assertStringContainsString('sortBy=name', $url);
        self::assertStringContainsString('orderBy=desc', $url);
        self::assertStringContainsString('search=sh', $url);
        self::assertStringNotContainsString('page=', $url);
    }

    public function testColumnVisibilityIsOptInAndPreservesQueryState(): void
    {
        $table = $this->table(Request::create('/statistics/closures', 'GET', [
            'scope' => 'hospital',
            'period' => 'month',
            'page' => '3',
        ]));
        $table->paginationRoute = 'app_stats_closure_analytics_details';
        $table->columns = [
            ['key' => 'start', 'label' => 'Start', 'required' => true],
            ['key' => 'hospital', 'label' => 'Hospital', 'configurable' => true],
            ['key' => 'reason', 'label' => 'Reason', 'configurable' => true, 'visible' => false],
            ['key' => 'internal', 'label' => 'Internal', 'visible' => false],
        ];

        self::assertSame(['start', 'hospital'], array_map(
            static fn (DataTableColumn $column): string => $column->key,
            $table->getVisibleColumns(),
        ));
        self::assertFalse($table->getShouldShowColumnVisibility());

        $table->columnVisibilityEnabled = true;
        self::assertTrue($table->getShouldShowColumnVisibility());
        self::assertCount(3, $table->getColumnVisibilityChoices());
        $hospitalChoice = $table->getColumnVisibilityChoices()[1];
        self::assertStringContainsString('columns=', $hospitalChoice['url']);
        self::assertStringContainsString('scope=hospital', $hospitalChoice['url']);
        self::assertStringContainsString('period=month', $hospitalChoice['url']);
        self::assertStringNotContainsString('page=', $hospitalChoice['url']);
    }

    public function testColumnVisibilityAcceptsOnlyConfiguredKeysAndKeepsRequiredColumns(): void
    {
        $table = $this->table(Request::create('/statistics/closures', 'GET', [
            'columns' => 'reason,unknown,reason',
        ]));
        $table->columnVisibilityEnabled = true;
        $table->columns = [
            ['key' => 'start', 'label' => 'Start', 'required' => true],
            ['key' => 'hospital', 'label' => 'Hospital', 'configurable' => true],
            ['key' => 'reason', 'label' => 'Reason', 'configurable' => true, 'visible' => false],
            ['key' => 'internal', 'label' => 'Internal', 'visible' => false],
        ];

        self::assertSame(['start', 'reason'], array_map(
            static fn (DataTableColumn $column): string => $column->key,
            $table->getVisibleColumns(),
        ));

        $arrayInput = $this->table(Request::create('/statistics/closures', 'GET', [
            'columns' => ['reason'],
        ]));
        $arrayInput->columnVisibilityEnabled = true;
        $arrayInput->columns = $table->columns;
        self::assertSame(['start'], array_map(
            static fn (DataTableColumn $column): string => $column->key,
            $arrayInput->getVisibleColumns(),
        ));
    }

    public function testColumnOrderingIsOptInAndKeepsHiddenPositions(): void
    {
        $request = Request::create('/statistics/closures', 'GET', [
            'columns' => 'hospital,reason',
            'columnOrder' => 'reason,start,hospital,unknown,duration',
            'scope' => 'public',
            'sortBy' => 'startsAt',
            'orderBy' => 'desc',
            'limit' => '50',
            'page' => '2',
        ]);
        $table = $this->table($request);
        $table->paginationRoute = 'app_stats_closure_analytics_details';
        $table->columnVisibilityEnabled = true;
        $table->columns = [
            ['key' => 'start', 'label' => 'Start', 'required' => true],
            ['key' => 'hospital', 'label' => 'Hospital', 'configurable' => true],
            ['key' => 'duration', 'label' => 'Duration', 'configurable' => true, 'visible' => false],
            ['key' => 'reason', 'label' => 'Reason', 'configurable' => true, 'visible' => false],
        ];

        self::assertSame(['start', 'hospital', 'reason'], array_map(
            static fn (DataTableColumn $column): string => $column->key,
            $table->getVisibleColumns(),
        ), 'Existing tables ignore columnOrder until ordering is enabled.');

        $table->columnOrderingEnabled = true;
        self::assertSame(['reason', 'start', 'hospital'], array_map(
            static fn (DataTableColumn $column): string => $column->key,
            $table->getVisibleColumns(),
        ));

        $choices = $table->getColumnVisibilityChoices();
        self::assertSame(['reason', 'start', 'hospital', 'duration'], array_map(
            static fn (array $choice): string => $choice['column']->key,
            $choices,
        ));
        self::assertStringContainsString('columnOrder=start%2Creason%2Chospital%2Cduration', $choices[0]['moveDownUrl']);
        self::assertStringContainsString('scope=public', $choices[0]['moveDownUrl']);
        self::assertStringContainsString('sortBy=startsAt', $choices[0]['moveDownUrl']);
        self::assertStringContainsString('orderBy=desc', $choices[0]['moveDownUrl']);
        self::assertStringContainsString('limit=50', $choices[0]['moveDownUrl']);
        self::assertStringNotContainsString('page=', $choices[0]['moveDownUrl']);
    }

    public function testSortMenuAndResetClearPresentationState(): void
    {
        $table = $this->table(Request::create('/statistics/closures', 'GET', [
            'scope' => 'public',
            'columns' => 'hospital',
            'columnOrder' => 'hospital,start',
            'sortBy' => 'hospital',
            'orderBy' => 'asc',
            'limit' => '50',
            'page' => '2',
            'closureReasons' => ['technical_fault'],
        ]));
        $table->paginationRoute = 'app_stats_closure_analytics_details';
        $table->sortBy = 'hospital';
        $table->orderBy = 'asc';
        $table->columns = [
            ['key' => 'start', 'label' => 'Start', 'sortable' => true, 'required' => true],
            ['key' => 'hospital', 'label' => 'Hospital', 'sortable' => true, 'configurable' => true],
            ['key' => 'reason', 'label' => 'Reason', 'sortable' => true, 'configurable' => true, 'visible' => false],
        ];

        self::assertFalse($table->getShouldShowSortMenu());
        self::assertFalse($table->getShouldShowReset());

        $table->columnVisibilityEnabled = true;
        self::assertTrue($table->getShouldShowSortMenu());
        self::assertTrue($table->getShouldShowReset());

        $choices = $table->getSortChoices();
        self::assertSame(['start', 'hospital', 'reason'], array_map(
            static fn (array $choice): string => $choice['sortKey'],
            $choices,
        ));
        self::assertFalse($choices[0]['selected']);
        self::assertTrue($choices[1]['selected']);
        self::assertSame('asc', $table->getSelectedSortDirection());
        self::assertSame([25, 50, 100], $table->getPageSizes());

        $hidden = array_column($table->getSortFormHiddenFields(), 'value', 'name');
        self::assertSame('public', $hidden['scope']);
        self::assertSame('hospital', $hidden['columns']);
        self::assertSame('technical_fault', $hidden['closureReasons[0]']);
        self::assertArrayNotHasKey('sortBy', $hidden);
        self::assertArrayNotHasKey('limit', $hidden);
        self::assertArrayNotHasKey('page', $hidden);
        self::assertSame('/explore/state', $table->getSortFormAction());

        $sortResetUrl = $table->sortResetUrl();
        self::assertStringContainsString('scope=public', $sortResetUrl);
        self::assertStringContainsString('columns=hospital', $sortResetUrl);
        self::assertStringNotContainsString('sortBy=', $sortResetUrl);
        self::assertStringNotContainsString('orderBy=', $sortResetUrl);
        self::assertStringNotContainsString('limit=', $sortResetUrl);
        self::assertStringNotContainsString('page=', $sortResetUrl);

        $columnResetUrl = $table->columnResetUrl();
        self::assertStringContainsString('scope=public', $columnResetUrl);
        self::assertStringContainsString('sortBy=hospital', $columnResetUrl);
        self::assertStringContainsString('limit=50', $columnResetUrl);
        self::assertStringNotContainsString('columns=', $columnResetUrl);
        self::assertStringNotContainsString('columnOrder=', $columnResetUrl);
        self::assertStringNotContainsString('page=', $columnResetUrl);

        $resetUrl = $table->resetUrl();
        self::assertStringContainsString('scope=public', $resetUrl);
        self::assertStringNotContainsString('columns=', $resetUrl);
        self::assertStringNotContainsString('columnOrder=', $resetUrl);
        self::assertStringNotContainsString('sortBy=', $resetUrl);
        self::assertStringNotContainsString('orderBy=', $resetUrl);
        self::assertStringNotContainsString('limit=', $resetUrl);
        self::assertStringNotContainsString('page=', $resetUrl);
    }

    public function testResolvedColumnStateCanBeProvidedWithoutQueryParameters(): void
    {
        $table = $this->table(Request::create('/statistics/closures'));
        $table->columnVisibilityEnabled = true;
        $table->columnOrderingEnabled = true;
        $table->visibleColumnKeys = ['reason'];
        $table->columnOrder = ['reason', 'start', 'hospital'];
        $table->columns = [
            ['key' => 'start', 'label' => 'Start', 'required' => true],
            ['key' => 'hospital', 'label' => 'Hospital', 'configurable' => true],
            ['key' => 'reason', 'label' => 'Reason', 'configurable' => true, 'visible' => false],
        ];

        self::assertSame(['reason', 'start'], array_map(
            static fn (DataTableColumn $column): string => $column->key,
            $table->getVisibleColumns(),
        ));
    }

    public function testCursorFooterUrlsAndHiddenWhenEmpty(): void
    {
        $table = $this->table(Request::create('/explore/allocation', 'GET', [
            'cursor' => 'abc',
        ]));
        $table->paginationRoute = 'app_explore_allocation_list';
        $table->paginator = new CursorPaginator(
            [['publicId' => '1']],
            25,
            'next-cursor',
            null,
            1200,
        );
        $table->columns = [DataTableColumn::fromArray(['key' => 'name', 'label' => 'Name'])];

        self::assertTrue($table->isCursorPaginator());
        self::assertTrue($table->getShouldShowFooter());
        self::assertNotNull($table->cursorPreviousUrl());
        self::assertStringContainsString('cursor=next-cursor', (string) $table->cursorNextUrl());

        $table->paginator = new CursorPaginator([], 25, null);
        self::assertFalse($table->getShouldShowFooter());
    }

    public function testCellHrefBadgeNumberAndRowClass(): void
    {
        $table = $this->table(Request::create('/explore/hospital'));
        $table->paginationRoute = 'app_explore_hospital_show';
        $table->sortBy = 'name';
        $table->columns = [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
        ];
        $table->rowClassProperty = 'closed';
        $table->rowClass = 'is-closed';
        $link = DataTableColumn::fromArray([
            'key' => 'name',
            'route' => 'app_explore_hospital_show',
            'routeParams' => ['publicId' => 'publicId'],
        ]);
        $beds = DataTableColumn::fromArray([
            'key' => 'size',
            'numberProperty' => 'beds',
        ]);

        self::assertSame('Kiel', $table->cellValue(['name' => 'Kiel'], $link));
        self::assertSame('/explore/state?publicId=abc', $table->cellHref(['publicId' => 'abc'], $link));
        self::assertNull($table->cellHref(['publicId' => ''], $link));
        self::assertNull($table->cellHref(['name' => 'Kiel'], DataTableColumn::fromArray(['key' => 'name'])));
        self::assertSame('/explore/state', $table->cellHref(['publicId' => 'abc'], DataTableColumn::fromArray([
            'key' => 'name',
            'route' => 'app_explore_hospital_show',
            'routeParams' => 'invalid',
        ])));
        self::assertSame('/explore/state', $table->cellHref(['publicId' => 'abc'], DataTableColumn::fromArray([
            'key' => 'name',
            'route' => 'app_explore_hospital_show',
            'routeParams' => [1 => 'publicId'],
        ])));
        self::assertSame('/explore/state', $table->cellHref(['publicId' => 'abc'], DataTableColumn::fromArray([
            'key' => 'name',
            'route' => 'app_explore_hospital_show',
            'routeParams' => ['publicId' => 1],
        ])));
        self::assertSame(12, $table->badgeNumber(['beds' => 12], $beds));
        self::assertNull($table->badgeNumber(['beds' => 12], DataTableColumn::fromArray(['key' => 'size'])));
        self::assertSame('Urban', $table->badgeView(['location' => 'Urban'], DataTableColumn::fromArray([
            'key' => 'location',
            'badgePalette' => 'hospital_location',
        ]))->label);
        self::assertSame('', $table->badgeView(['location' => false], DataTableColumn::fromArray([
            'key' => 'location',
            'badgePalette' => 12,
        ]))->label);
        self::assertSame('is-closed', $table->rowCssClass(['closed' => true]));
        self::assertSame('', $table->rowCssClass(['closed' => false]));
        self::assertSame('', $this->table(Request::create('/explore/hospital'))->rowCssClass(['closed' => true]));
        self::assertTrue($table->isSortedBy(DataTableColumn::fromArray(['key' => 'name'])));
        self::assertTrue($table->isDeclarative());
    }

    public function testPageSizeLinksAndEmptyRowSources(): void
    {
        $table = $this->table(Request::create('/explore/state', 'GET', ['page' => '2']));
        $table->paginationRoute = 'app_explore_state_list';
        $table->paginator = new CursorPaginator([['name' => 'Kiel']], 50, 'next');

        $links = $table->getPageSizeLinks();
        self::assertCount(3, $links);
        self::assertTrue($links[1]['active']);
        self::assertStringContainsString('limit=50', $links[1]['url']);

        $offsetLinks = $this->table(Request::create('/explore/state'));
        $offsetLinks->paginationRoute = 'app_explore_state_list';
        self::assertStringContainsString('page=1', $offsetLinks->getPageSizeLinks()[0]['url']);

        $empty = $this->table(Request::create('/explore/state'));
        self::assertSame([], $empty->getRowItems());
        self::assertFalse($empty->isDeclarative());
        $empty->rows = [['name' => 'Kiel']];
        self::assertSame([['name' => 'Kiel']], $empty->getRowItems());

        $withoutRequest = new DataTable(new RequestStack(), new DataTableTestUrlGenerator(), new DataTableValueResolver(), new BadgePalette());
        $withoutRequest->columns = [['key' => 'name', 'label' => 'Name', 'sortable' => true]];
        self::assertNull($withoutRequest->cursorPreviousUrl());
        self::assertSame('#', $withoutRequest->sortUrl($withoutRequest->getVisibleColumns()[0]));
    }

    public function testCursorNextUrlRequiresANonEmptyCursor(): void
    {
        $table = $this->table(Request::create('/explore/allocation'));
        $table->paginationRoute = 'app_explore_allocation_list';
        $table->paginator = new CursorPaginator([['name' => 'Kiel']], 25, '');

        self::assertNull($table->cursorNextUrl());
        $table->paginator = null;
        self::assertNull($table->cursorNextUrl());

        $emptyCursor = $this->table(Request::create('/explore/allocation', 'GET', ['cursor' => '']));
        $emptyCursor->paginationRoute = 'app_explore_allocation_list';
        self::assertNull($emptyCursor->cursorPreviousUrl());
    }

    public function testUxPaginatorSuppliesRowsAndPageSize(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; ++$i) {
            $rows[] = ['name' => 'Row '.$i];
        }

        $table = $this->table(Request::create('/explore/infection', 'GET', ['page' => '2', 'search' => 'alpha']));
        $table->paginationRoute = 'app_explore_infection_list';
        $table->paginator = PaginatorFactory::create()
            ->query($rows)
            ->perPage(25)
            ->paginate(page: 2);

        self::assertTrue($table->isUxPaginator());
        self::assertFalse($table->isCursorPaginator());
        self::assertTrue($table->getShouldShowFooter());
        self::assertSame(25, $table->getPageSize());
        self::assertCount(5, $table->getRowItems());
        $first = $table->getRowItems()[0];
        self::assertIsArray($first);
        self::assertSame('Row 26', $first['name']);

        $links = $table->getPageSizeLinks();
        self::assertTrue($links[0]['active']);
        self::assertStringContainsString('limit=25', $links[0]['url']);
        self::assertStringContainsString('page=1', $links[0]['url']);
        self::assertStringContainsString('search=alpha', $links[0]['url']);
    }

    public function testPaginationRouteFallsBackToCurrentRequest(): void
    {
        $request = Request::create('/explore/state');
        $request->attributes->set('_route', 'app_explore_state_list');
        $request->attributes->set('_route_params', 'invalid');
        $table = $this->table($request);
        $table->paginationRoute = '';
        $table->columns = [['key' => 'name', 'label' => 'Name', 'sortable' => true]];

        self::assertStringContainsString('sortBy=name', $table->sortUrl($table->getVisibleColumns()[0]));

        $withoutRoute = Request::create('/explore/state');
        $withoutRoute->attributes->set('_route', '');
        $blank = $this->table($withoutRoute);
        $blank->paginationRoute = '';
        $blank->columns = [['key' => 'name', 'label' => 'Name', 'sortable' => true]];
        self::assertSame('#', $blank->sortUrl($blank->getVisibleColumns()[0]));
    }

    private function table(Request $request): DataTable
    {
        $stack = new RequestStack([$request]);

        return new DataTable($stack, new DataTableTestUrlGenerator(), new DataTableValueResolver(), new BadgePalette());
    }
}

final class DataTableTestUrlGenerator implements UrlGeneratorInterface
{
    /**
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $query = http_build_query($parameters);

        return '/explore/state'.('' === $query ? '' : '?'.$query);
    }

    #[\Override]
    public function setContext(RequestContext $context): void
    {
    }

    #[\Override]
    public function getContext(): RequestContext
    {
        return new RequestContext();
    }
}
