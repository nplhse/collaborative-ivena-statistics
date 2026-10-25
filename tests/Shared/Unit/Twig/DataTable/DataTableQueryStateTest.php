<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\DataTable;

use App\Shared\UI\Twig\DataTable\DataTableQueryState;
use PHPUnit\Framework\TestCase;

final class DataTableQueryStateTest extends TestCase
{
    public function testScalarFieldsDropPaginationOmittedNonScalarAndEmptyValues(): void
    {
        self::assertSame(
            ['tier' => 'full', 'columns' => 'name,beds', 'limit' => '50'],
            DataTableQueryState::scalarFields([
                'tier' => 'full',
                'search' => 'klinik',
                'columns' => 'name,beds',
                'limit' => 50,
                'page' => '3',
                'cursor' => 'abc',
                'after' => '1',
                'before' => '2',
                'nested' => ['a' => 'b'],
                'empty' => '',
            ], ['search']),
        );
    }

    public function testScalarFieldsSkipNumericAndEmptyKeys(): void
    {
        self::assertSame(
            ['tier' => 'full'],
            DataTableQueryState::scalarFields([0 => 'positional', '' => 'blank', 'tier' => 'full']),
        );
    }

    public function testViewFieldsKeepOnlySortPageSizeAndColumns(): void
    {
        self::assertSame(
            [
                'sortBy' => 'beds',
                'orderBy' => 'desc',
                'limit' => '50',
                'columns' => 'name,beds',
                'columnOrder' => 'beds,name',
            ],
            DataTableQueryState::viewFields([
                'sortBy' => 'beds',
                'orderBy' => 'desc',
                'limit' => '50',
                'columns' => 'name,beds',
                'columnOrder' => 'beds,name',
                'search' => 'klinik',
                'tier' => 'full',
                'page' => '2',
            ]),
        );
    }
}
