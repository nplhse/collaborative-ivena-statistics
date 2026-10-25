<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\DataTable;

use App\Shared\Application\DataTable\DataTablePreferenceSchema;
use PHPUnit\Framework\TestCase;

final class DataTablePreferenceSchemaTest extends TestCase
{
    public function testFromColumnsDerivesOrderVisibleAndRequiredColumns(): void
    {
        $schema = DataTablePreferenceSchema::fromColumns(
            'test.table',
            [
                ['key' => 'icon'],
                ['key' => 'name', 'required' => true, 'visible' => false],
                ['key' => 'city', 'configurable' => true, 'visible' => false],
                ['key' => 'beds', 'configurable' => true],
                ['label' => 'no key'],
                ['key' => ''],
            ],
            sortKeys: ['name', 'beds'],
            defaultSortBy: 'name',
            defaultOrderBy: 'asc',
        );

        self::assertSame('test.table', $schema->key);
        self::assertSame(['icon', 'name', 'city', 'beds'], $schema->columnOrder);
        self::assertSame(['icon', 'name', 'beds'], $schema->defaultVisibleColumns);
        self::assertSame(['name'], $schema->requiredColumns);
        self::assertSame(['name', 'beds'], $schema->sortKeys);
        self::assertSame('name', $schema->defaultSortBy);
        self::assertSame('asc', $schema->defaultOrderBy);
    }

    public function testFromColumnsWithoutSortKeysHasNoDefaultSort(): void
    {
        $defaults = DataTablePreferenceSchema::fromColumns('test.table', [['key' => 'name']])->defaults();

        self::assertNull($defaults->sortBy);
        self::assertNull($defaults->orderBy);
        self::assertSame(25, $defaults->pageSize);
    }
}
