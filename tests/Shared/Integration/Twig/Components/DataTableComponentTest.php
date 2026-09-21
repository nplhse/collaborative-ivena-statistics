<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\Shared\UI\Twig\Components\DataTable;
use Doctrine\ORM\QueryBuilder as DoctrineQueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
            'paginator' => $this->offsetPaginator([['name' => 'Alpha']], 10, 25),
            'columns' => [
                ['key' => 'name', 'label' => 'label.name', 'type' => 'text'],
            ],
        ]);

        self::assertStringContainsString('Showing 1-10 of 10 results.', $html);
        self::assertStringContainsString('25 records', $html);
        self::assertStringContainsString('limit=50', $html);
        self::assertStringContainsString('id="result-count"', $html);
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

    /**
     * @param list<array<string, mixed>> $results
     */
    private function offsetPaginator(array $results, int $total, int $pageSize = 25, int $page = 1): Paginator
    {
        $paginator = new Paginator($this->createStub(DoctrineQueryBuilder::class), $pageSize);
        $reflection = new \ReflectionClass($paginator);
        $reflection->getProperty('currentPage')->setValue($paginator, $page);
        $reflection->getProperty('numResults')->setValue($paginator, $total);
        $reflection->getProperty('results')->setValue($paginator, new \ArrayIterator($results));

        return $paginator;
    }
}
