<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\UI\Twig;

use App\Import\Infrastructure\Query\ListImportsQuery;
use App\Import\UI\Twig\ImportListColumns;
use App\Shared\UI\Twig\DataTable\DataTableColumn;
use PHPUnit\Framework\TestCase;

final class ImportListColumnsTest extends TestCase
{
    public function testDefaultViewKeepsTheFormerColumns(): void
    {
        $schema = new ImportListColumns()->preferenceSchema();

        self::assertSame(ImportListColumns::PREFERENCE_KEY, $schema->key);
        self::assertSame(
            ['type', 'name', 'status', 'hospital', 'createdAt', 'lastChange', 'lastChangedBy'],
            $schema->defaultVisibleColumns,
        );
        self::assertSame(['name'], $schema->requiredColumns);
        self::assertSame('createdAt', $schema->defaultSortBy);
        self::assertSame('desc', $schema->defaultOrderBy);
    }

    public function testSortableColumnsMatchTheSortKeys(): void
    {
        $sortKeys = array_values(array_map(
            static fn (DataTableColumn $column): string => $column->resolvedSortKey(),
            array_filter(
                array_map(DataTableColumn::fromArray(...), ImportListColumns::columns()),
                static fn (DataTableColumn $column): bool => $column->sortable,
            ),
        ));

        self::assertEqualsCanonicalizing(ImportListColumns::SORT_KEYS, $sortKeys);
    }

    public function testEverySortKeyHasItsOwnDatabaseField(): void
    {
        $fields = array_map(ListImportsQuery::sortField(...), ImportListColumns::SORT_KEYS);

        self::assertSame($fields, array_values(array_unique($fields)));
    }
}
