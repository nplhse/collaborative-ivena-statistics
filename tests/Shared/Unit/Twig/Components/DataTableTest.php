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
