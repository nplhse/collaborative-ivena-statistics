<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\UI\Twig\Components\DataTable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\Pagination\NumberedPaginationInterface;
use Symfony\UX\Pagination\PaginatorInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class DataTableComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testContentBlockRemainsEscapeHatch(): void
    {
        $html = (string) $this->renderTwigComponent(
            'DataTable',
            ['title' => 'Legacy'],
            '<table class="table data-table-card"><tbody><tr><td>Legacy cell</td></tr></tbody></table>',
        );

        self::assertStringContainsString('Legacy', $html);
        self::assertStringContainsString('Legacy cell', $html);
        self::assertStringNotContainsString('<thead>', $html);
    }

    public function testRendersEmptyStateForDeclarativeTable(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
            'rows' => [],
        ]);

        self::assertStringContainsString('empty-title', $html);
        self::assertStringContainsString('Sorry, no results found.', $html);
        self::assertStringNotContainsString('card-footer', $html);
        self::assertStringNotContainsString('id="result-count"', $html);
        self::assertStringNotContainsString('empty-action', $html);
    }

    public function testForwardsEmptyActionsIntoEmptyState(): void
    {
        $html = (string) $this->renderTwigComponent(
            'DataTable',
            [
                'columns' => [
                    ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
                ],
                'rows' => [],
                'emptyTitle' => 'No imports yet',
                'emptyDescription' => 'Import your first dataset.',
                'emptyIcon' => 'tabler:database-off',
            ],
            '',
            ['empty_actions' => '<a class="btn btn-primary" href="/import/new">Import data</a>'],
        );

        self::assertStringContainsString('No imports yet', $html);
        self::assertStringContainsString('Import your first dataset.', $html);
        self::assertStringContainsString('empty-action', $html);
        self::assertStringContainsString('Import data', $html);
        self::assertStringContainsString('href="/import/new"', $html);
    }

    public function testEmptyTableKeepsHiddenResultCountForHeaderMirror(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'paginator' => $this->offsetPaginator([]),
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
        ]);

        self::assertStringContainsString('Sorry, no results found.', $html);
        self::assertStringNotContainsString('card-footer', $html);
        self::assertStringContainsString('id="result-count"', $html);
        self::assertStringContainsString('visually-hidden', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('Showing 0-0 of 0 results.', $html);
    }

    public function testRendersBadgeCustomCellAndCursorFooter(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/explore/hospital', 'GET', [
            '_route' => 'app_explore_hospital_list',
        ]));

        $html = (string) $this->renderTwigComponent('DataTable', [
            'paginationRoute' => 'app_explore_hospital_list',
            'sortBy' => 'name',
            'orderBy' => 'asc',
            'paginator' => new CursorPaginator(
                [['name' => 'Kiel', 'location' => 'Urban']],
                25,
                'next',
                null,
                80,
            ),
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text', 'sortable' => true],
                [
                    'key' => 'location',
                    'label' => 'label.location',
                    'type' => 'badge',
                    'badgePalette' => 'hospital_location',
                    'property' => 'location',
                ],
                [
                    'key' => 'custom',
                    'label' => 'Custom',
                    'type' => 'custom',
                    'cellTemplate' => '@Shared/components/data_table/_cell_text.html.twig',
                    'property' => 'name',
                ],
            ],
        ]);

        self::assertStringContainsString('Kiel', $html);
        self::assertStringContainsString('bg-indigo', $html);
        self::assertStringContainsString('Urban', $html);
        self::assertStringContainsString('id="result-count"', $html);
        self::assertStringContainsString('card-footer', $html);
        self::assertStringContainsString('Next', $html);
        self::assertStringContainsString('Showing approx.', $html);
    }

    public function testCursorFooterWithoutEstimateShowsGenericCopy(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/explore/allocation', 'GET', [
            '_route' => 'app_explore_allocation_list',
        ]));

        $html = (string) $this->renderTwigComponent('DataTable', [
            'paginationRoute' => 'app_explore_allocation_list',
            'paginator' => new CursorPaginator(
                [['name' => 'Kiel']],
                25,
                'next',
            ),
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
        ]);

        self::assertStringContainsString('Showing results.', $html);
        self::assertStringNotContainsString('Showing approx.', $html);
        self::assertStringContainsString('id="result-count"', $html);
        self::assertStringContainsString('card-footer', $html);
    }

    public function testEmptyCursorTableKeepsHiddenResultCountForHeaderMirror(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'paginator' => new CursorPaginator([], 25, null),
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
        ]);

        self::assertStringContainsString('Sorry, no results found.', $html);
        self::assertStringNotContainsString('card-footer', $html);
        self::assertStringContainsString('id="result-count"', $html);
        self::assertStringContainsString('visually-hidden', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('Showing results.', $html);
    }

    public function testRendersOffsetFooterWithRangeAndPageSize(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $request = Request::create('/explore/hospital');
        $request->attributes->set('_route', 'app_explore_hospital_list');
        $request->attributes->set('_route_params', []);
        $requestStack->push($request);

        $html = (string) $this->renderTwigComponent('DataTable', [
            'paginationRoute' => 'app_explore_hospital_list',
            'paginator' => $this->offsetPaginator(array_fill(0, 10, ['name' => 'Alpha'])),
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
        ]);

        self::assertStringContainsString('Showing 1-10 of 10 results.', $html);
        self::assertStringContainsString('25 records', $html);
        self::assertStringContainsString('limit=50', $html);
        self::assertStringContainsString('id="result-count"', $html);
        $pageSizePos = strpos($html, '25 records');
        $countPos = strpos($html, 'Showing 1-10 of 10 results.');
        self::assertNotFalse($pageSizePos);
        self::assertNotFalse($countPos);
        self::assertLessThan($countPos, $pageSizePos);
        self::assertStringContainsString('flex-wrap gap-3', $html);
    }

    public function testNumberedFooterSitsAtTheTrailingEdge(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $request = Request::create('/explore/hospital', 'GET', ['page' => '1']);
        $request->attributes->set('_route', 'app_explore_hospital_list');
        $request->attributes->set('_route_params', []);
        $requestStack->push($request);

        $html = (string) $this->renderTwigComponent('DataTable', [
            'paginationRoute' => 'app_explore_hospital_list',
            'paginator' => $this->offsetPaginator(array_fill(0, 30, ['name' => 'Alpha']), 10),
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
        ]);

        self::assertStringContainsString('Showing 1-10 of 30 results.', $html);
        self::assertStringContainsString('class="ux-pagination-bootstrap ms-auto"', $html);
        self::assertStringContainsString('class="pagination m-0"', $html);
        self::assertMatchesRegularExpression(
            '/id="result-count".*ux-pagination-bootstrap ms-auto/s',
            $html,
        );
    }

    public function testMountedComponentExposesVisibleColumns(): void
    {
        $component = $this->mountTwigComponent('DataTable', [
            'columns' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'hidden', 'label' => 'Hidden', 'visible' => false],
            ],
        ]);

        self::assertInstanceOf(DataTable::class, $component);
        self::assertTrue($component->isDeclarative());
        self::assertCount(1, $component->getVisibleColumns());
    }

    public function testRendersOptInColumnPickerAndAccessibleSortState(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $request = Request::create('/explore/hospital', 'GET', ['columns' => 'name']);
        $request->attributes->set('_route', 'app_explore_hospital_list');
        $request->attributes->set('_route_params', []);
        $requestStack->push($request);

        $html = (string) $this->renderTwigComponent('DataTable', [
            'title' => 'Hospitals',
            'columnVisibilityEnabled' => true,
            'paginationRoute' => 'app_explore_hospital_list',
            'sortBy' => 'name',
            'orderBy' => 'desc',
            'rows' => [['name' => 'Kiel', 'location' => 'Urban']],
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'sortable' => true, 'required' => true],
                ['key' => 'location', 'label' => 'label.location', 'configurable' => true],
            ],
        ]);

        self::assertStringContainsString('role="menuitemcheckbox"', $html);
        self::assertStringContainsString('aria-sort="descending"', $html);
        self::assertStringContainsString('scope="col"', $html);
        self::assertStringContainsString('data-testid="data-table-configure"', $html);
        self::assertStringContainsString('btn-group-sm', $html);
        self::assertStringContainsString('data-testid="data-table-columns"', $html);
        self::assertStringContainsString('data-testid="data-table-sort"', $html);
        self::assertStringNotContainsString('data-testid="data-table-reset"', $html);
        self::assertMatchesRegularExpression('/data-testid="data-table-sort".*data-testid="data-table-columns"/s', $html);
        self::assertStringContainsString('btn-outline-secondary', $html);
        self::assertStringContainsString('data-table-popover', $html);
        self::assertStringContainsString('data-testid="data-table-sort-form"', $html);
        self::assertStringContainsString('Choose the sort column, direction, and page size.', $html);
        self::assertStringContainsString('Select columns and arrange their left-to-right order.', $html);
        self::assertStringContainsString('name="sortBy"', $html);
        self::assertStringContainsString('name="orderBy"', $html);
        self::assertStringContainsString('name="limit"', $html);
        self::assertStringContainsString('Ascending', $html);
        self::assertStringContainsString('Descending', $html);
        self::assertStringContainsString('Results per page', $html);
        self::assertStringContainsString('Apply', $html);
        self::assertStringContainsString('aria-label="Sort"', $html);
        self::assertStringContainsString('aria-label="Columns"', $html);
        self::assertStringContainsString('Kiel', $html);
        self::assertStringNotContainsString('Urban', $html);
    }

    public function testToolbarBlockRendersBothViewButtons(): void
    {
        $html = (string) $this->renderTwigComponent(
            'DataTable',
            [
                'title' => 'Hospitals',
                'columnVisibilityEnabled' => true,
                'rows' => [['name' => 'Kiel']],
                'columns' => [
                    ['key' => 'name', 'label' => 'label.name', 'required' => true],
                    ['key' => 'location', 'label' => 'label.location', 'configurable' => true],
                ],
            ],
            '',
            ['toolbar' => '<div class="btn-group" data-testid="stats-closure-table-view"><a class="btn btn-icon btn-outline-secondary" href="/events">Events</a><a class="btn btn-icon btn-outline-secondary" href="/intervals">Intervals</a></div>'],
        );

        self::assertStringContainsString('href="/events"', $html);
        self::assertStringContainsString('href="/intervals"', $html);
        self::assertStringContainsString('data-testid="stats-closure-table-view"', $html);
        self::assertMatchesRegularExpression('/data-testid="stats-closure-table-view".*href="\\/events".*href="\\/intervals"/s', $html);
    }

    public function testColumnPickerRemainsAbsentForExistingConfiguration(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'title' => 'Hospitals',
            'rows' => [['name' => 'Kiel']],
            'columns' => [
                ['key' => 'name', 'label' => 'label.name'],
            ],
        ]);

        self::assertStringNotContainsString('role="menuitemcheckbox"', $html);
    }

    public function testRendersPersistentOrderedColumnForm(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'title' => 'Hospitals',
            'columnVisibilityEnabled' => true,
            'columnOrderingEnabled' => true,
            'visibleColumnKeys' => ['location'],
            'columnOrder' => ['location', 'name'],
            'preferenceKey' => 'test.hospitals',
            'preferenceSaveUrl' => '/account/data-table-preferences',
            'preferenceCsrfToken' => 'csrf',
            'preferenceReturnUrl' => '/explore/hospitals?scope=public',
            'rows' => [['name' => 'Kiel', 'location' => 'Urban']],
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'sortable' => true, 'required' => true],
                ['key' => 'location', 'label' => 'label.location', 'configurable' => true],
            ],
        ]);

        self::assertStringContainsString('data-controller="data-table-columns"', $html);
        self::assertStringContainsString('#1', $html);
        self::assertStringContainsString('#2', $html);
        self::assertMatchesRegularExpression('/name="columnOrder\\[\\]" value="location".*name="columnOrder\\[\\]" value="name"/s', $html);
        self::assertMatchesRegularExpression('/name="visibleColumns\\[\\]"[^>]*value="location"|value="location"[^>]*name="visibleColumns\\[\\]"/', $html);
        self::assertStringContainsString('name="action" value="reset-columns"', $html);
        self::assertStringContainsString('name="action" value="reset-sort"', $html);
        self::assertStringContainsString('name="action" value="save"', $html);
        self::assertMatchesRegularExpression('/<th[^>]*>.*Location.*<th[^>]*>.*Name/s', $html);
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return NumberedPaginationInterface<mixed>
     */
    private function offsetPaginator(array $results, int $pageSize = 25): NumberedPaginationInterface
    {
        $paginator = self::getContainer()->get(PaginatorInterface::class);
        self::assertInstanceOf(PaginatorInterface::class, $paginator);

        return $paginator->query($results)->perPage($pageSize)->paginate();
    }
}
