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
