<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Twig;

use App\Allocation\UI\Twig\CatalogListColumns;
use App\Shared\UI\Twig\DataTable\DataTableColumnType;
use PHPUnit\Framework\TestCase;

final class CatalogListColumnsTest extends TestCase
{
    public function testStandardColumnsIncludeNameLastChangeAndUser(): void
    {
        $columns = CatalogListColumns::standardColumns('app_explore_state_show');

        self::assertCount(3, $columns);
        self::assertSame('name', $columns[0]->key);
        self::assertSame(DataTableColumnType::Link, $columns[0]->type);
        self::assertSame('app_explore_state_show', $columns[0]->option('route'));
        self::assertSame('lastChange', $columns[1]->key);
        self::assertSame('lastChangedBy', $columns[2]->key);
        self::assertSame('col-8', $columns[0]->width);
    }

    public function testExtraColumnNarrowsNameWidth(): void
    {
        $columns = CatalogListColumns::standard('app_explore_dispatch_area_show', [
            [
                'key' => 'state',
                'type' => 'text',
                'label' => 'label.state',
                'property' => 'state',
                'width' => 'col-2',
            ],
        ]);

        self::assertSame('col-4', $columns[0]['width']);
        self::assertSame('state', $columns[1]['key']);
        self::assertCount(4, $columns);
    }
}
