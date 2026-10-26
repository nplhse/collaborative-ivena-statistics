<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\UI\Twig\Components\DataTable;
use App\Shared\UI\Twig\DataTable\DataTableBulkAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\Pagination\NumberedPaginationInterface;
use Symfony\UX\Pagination\PaginatorInterface;
use Symfony\UX\Pagination\Test\PaginatorFactory;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class DataTableComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testContentBlockRemainsEscapeHatch(): void
    {
        $html = (string) $this->renderTwigComponent(
            'DataTable',
            ['title' => 'Legacy'],
            '<table class="table card-table"><tbody><tr><td>Legacy cell</td></tr></tbody></table>',
        );

        self::assertStringContainsString('Legacy', $html);
        self::assertStringContainsString('Legacy cell', $html);
        self::assertStringNotContainsString('<thead>', $html);
    }

    public function testBulkSelectionIsOffWithoutActions(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'columns' => [['key' => 'name', 'label' => 'label.name', 'type' => 'text']],
            'rows' => [['id' => 1, 'name' => 'Alpha']],
            'bulkFormAction' => '/bulk',
            'bulkCsrfToken' => 'token',
        ]);

        self::assertStringNotContainsString('bulk-selection', $html);
        self::assertStringNotContainsString('name="ids[]"', $html);
    }

    public function testRendersBulkSelectionToolbarAndCountConfirmation(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'columns' => [['key' => 'name', 'label' => 'label.name', 'type' => 'text']],
            'rows' => [
                ['id' => 7, 'name' => 'Alpha', 'selectable' => true],
                ['id' => 8, 'name' => 'Beta', 'selectable' => false],
            ],
            'bulkActions' => [
                new DataTableBulkAction('reprocess', 'Reprocess', 'tabler:refresh'),
                new DataTableBulkAction(
                    'delete',
                    'Delete',
                    'tabler:trash',
                    'outline-danger',
                    confirmTitleOne: 'Really delete this import?',
                    confirmTitleOther: 'Really delete these %count% imports?',
                    confirmMessage: 'This cannot be undone.',
                    confirmLabel: 'Delete now',
                    confirmVariant: 'danger',
                ),
            ],
            'bulkFormAction' => '/import/bulk',
            'bulkCsrfToken' => 'csrf-value',
            'bulkId' => 'import-bulk',
            'bulkSelectableProperty' => 'selectable',
        ]);

        self::assertStringContainsString('data-controller="bulk-selection"', $html);
        self::assertStringContainsString('data-bulk-selection-selected-label-value="%count% selected"', $html);
        self::assertStringContainsString('data-testid="data-table-bulk-select-all"', $html);
        self::assertSame(1, substr_count($html, 'name="ids[]"'), 'Rows without the selectable flag get no checkbox.');
        self::assertStringContainsString('value="7"', $html);
        self::assertStringContainsString('form="import-bulk-form"', $html);
        self::assertStringContainsString('aria-label="Select row 7"', $html);

        $form = new Crawler($html)->filter('form#import-bulk-form');
        self::assertSame('post', $form->attr('method'));
        self::assertSame('/import/bulk', $form->attr('action'));
        self::assertStringContainsString('name="_token" value="csrf-value"', $html);
        self::assertMatchesRegularExpression('/type="submit"\s+name="bulk_action"\s+value="reprocess"/', $html, 'Actions without confirmation submit directly.');
        self::assertStringContainsString('data-bs-target="#import-bulk-confirm-delete"', $html);

        self::assertStringContainsString('id="import-bulk-confirm-delete"', $html);
        self::assertStringContainsString('data-count-one="Really delete this import?"', $html);
        self::assertStringContainsString('data-count-other="Really delete these %count% imports?"', $html);
        self::assertStringContainsString('This cannot be undone.', $html);
        self::assertMatchesRegularExpression('/form="import-bulk-form"\s+name="bulk_action"\s+value="delete"\s+class="btn btn-danger"/', $html);
        self::assertStringContainsString('Delete now', $html);
        self::assertStringNotContainsString('id="import-bulk-confirm-reprocess"', $html);
    }

    public function testBulkActionsShareTheHeaderWithIconOnlySettings(): void
    {
        $crawler = new Crawler((string) $this->renderTwigComponent('DataTable', $this->pickerProps() + [
            'bulkActions' => [new DataTableBulkAction('reprocess', 'Reprocess')],
            'bulkFormAction' => '/import/bulk',
            'bulkCsrfToken' => 'token',
        ]));

        $header = $crawler->filter('[data-testid="data-table-header"]');
        self::assertCount(1, $header);
        self::assertCount(1, $crawler->filter('.card-header'), 'Selection actions and settings share one header row.');
        // Count and "clear" on the left; the actions sit right next to sort and columns.
        self::assertCount(1, $header->filter('[data-testid="data-table-bulk-selection"].d-none'));
        $toolbar = $header->filter('[data-testid="data-table-toolbar"]');
        self::assertCount(1, $toolbar->filter('[data-testid="data-table-bulk-actions"].d-none'));
        self::assertCount(1, $toolbar->filter('[data-testid="data-table-configure"]'));
        self::assertSame(
            ['data-table-bulk-actions', 'data-table-configure'],
            $toolbar->children()->each(static fn (Crawler $child): string => (string) $child->attr('data-testid')),
        );
        foreach (['data-table-sort', 'data-table-columns'] as $testId) {
            $button = $header->filter(sprintf('[data-testid="%s"]', $testId));
            self::assertCount(1, $button->filter('span.visually-hidden'), $testId.' shows its icon only.');
            self::assertStringContainsString('btn-icon-only', (string) $button->attr('class'));
            self::assertNotSame('', (string) $button->attr('aria-label'));
        }
    }

    public function testSettingsKeepTheirLabelsWithoutBulkActions(): void
    {
        $crawler = new Crawler((string) $this->renderTwigComponent('DataTable', $this->pickerProps()));

        self::assertCount(0, $crawler->filter('[data-testid="data-table-bulk-actions"]'));
        foreach (['data-table-sort', 'data-table-columns'] as $testId) {
            $button = $crawler->filter(sprintf('[data-testid="%s"]', $testId));
            self::assertCount(0, $button->filter('span.visually-hidden'), $testId);
            self::assertCount(1, $button->filter('span.data-table-toolbar__label'), $testId);
            self::assertStringNotContainsString('btn-icon-only', (string) $button->attr('class'), $testId);
        }
    }

    public function testBulkTableWithoutSettingsStillReservesTheHeader(): void
    {
        $crawler = new Crawler((string) $this->renderTwigComponent('DataTable', [
            'columns' => [['key' => 'name', 'label' => 'label.name', 'type' => 'text']],
            'rows' => [['id' => 1, 'name' => 'Alpha']],
            'bulkActions' => [new DataTableBulkAction('reprocess', 'Reprocess')],
            'bulkFormAction' => '/bulk',
            'bulkCsrfToken' => 'token',
        ]));

        self::assertCount(1, $crawler->filter('[data-testid="data-table-header"] [data-testid="data-table-bulk-selection"]'));
        self::assertCount(1, $crawler->filter('[data-testid="data-table-header"] [data-testid="data-table-bulk-actions"]'));
        self::assertCount(0, $crawler->filter('[data-testid="data-table-configure"]'));
    }

    public function testEmptyBulkTableSpansTheSelectionColumn(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'columns' => [['key' => 'name', 'label' => 'label.name', 'type' => 'text']],
            'rows' => [],
            'bulkActions' => [new DataTableBulkAction('reprocess', 'Reprocess')],
            'bulkFormAction' => '/bulk',
            'bulkCsrfToken' => 'token',
        ]);

        self::assertStringContainsString('colspan="2"', $html);
    }

    public function testRendersEmptyStateForDeclarativeTable(): void
    {
        $html = (string) $this->renderTwigComponent('DataTable', [
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
            'rows' => [],
        ]);

        self::assertStringContainsString('class="table card-table"', $html);
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

    public function testNativeUxCursorPaginationRendersPreviousNextFooter(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/explore/allocation', 'GET', [
            '_route' => 'app_explore_allocation_list',
        ]));

        $rows = [['id' => 1, 'name' => 'Kiel'], ['id' => 2, 'name' => 'Lübeck'], ['id' => 3, 'name' => 'Flensburg']];
        $html = (string) $this->renderTwigComponent('DataTable', [
            'paginationRoute' => 'app_explore_allocation_list',
            'paginator' => PaginatorFactory::create()
                ->cursor($rows)
                ->orderBy('id', 'ASC')
                ->context('data-table-test')
                ->perPage(2)
                ->cursor(null)
                ->paginate(),
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
        ]);

        self::assertStringContainsString('Lübeck', $html);
        self::assertStringNotContainsString('Flensburg', $html);
        self::assertStringContainsString('Showing results.', $html);
        self::assertMatchesRegularExpression('/href="[^"]*cursor=[^"]+"/', $html);
        self::assertStringContainsString('page-item disabled', $html, 'The first page has a disabled previous link.');
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
     * A table with a sort menu and a column picker.
     *
     * @return array<string, mixed>
     */
    private function pickerProps(): array
    {
        return [
            'columnVisibilityEnabled' => true,
            'rows' => [['id' => 1, 'name' => 'Kiel', 'location' => 'Urban']],
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'sortable' => true, 'required' => true],
                ['key' => 'location', 'label' => 'label.location', 'configurable' => true],
            ],
        ];
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
