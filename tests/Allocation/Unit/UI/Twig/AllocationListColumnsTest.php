<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Twig;

use App\Allocation\UI\Twig\AllocationListColumns;
use App\Shared\UI\Twig\DataTable\DataTableColumn;
use PHPUnit\Framework\TestCase;

final class AllocationListColumnsTest extends TestCase
{
    public function testDefaultViewKeepsTheFormerColumnsAndPageSize(): void
    {
        $schema = new AllocationListColumns()->preferenceSchema();

        self::assertSame(AllocationListColumns::PREFERENCE_KEY, $schema->key);
        self::assertSame(
            ['timestamps', 'age', 'hospital', 'indication', 'urgency', 'properties', 'actions'],
            $schema->defaultVisibleColumns,
        );
        self::assertSame(['timestamps'], $schema->requiredColumns);
        self::assertSame(50, $schema->defaultPageSize);
        self::assertSame('arrivalAt', $schema->defaultSortBy);
        self::assertSame('desc', $schema->defaultOrderBy);
    }

    public function testSortingStaysOnTheKeysetKeys(): void
    {
        $sortKeys = array_values(array_map(
            static fn (DataTableColumn $column): string => $column->resolvedSortKey(),
            array_filter($this->columns(), static fn (DataTableColumn $column): bool => $column->sortable),
        ));

        self::assertEqualsCanonicalizing(AllocationListColumns::SORT_KEYS, $sortKeys);
    }

    public function testJoinedColumnsAreOptionalPickerColumns(): void
    {
        $optional = array_map(
            static fn (DataTableColumn $column): string => $column->key,
            array_filter($this->columns(), static fn (DataTableColumn $column): bool => $column->configurable && !$column->visible),
        );

        self::assertSame([], array_diff(AllocationListColumns::JOINED_COLUMNS, $optional));
    }

    /**
     * @return list<DataTableColumn>
     */
    private function columns(): array
    {
        return array_map(DataTableColumn::fromArray(...), AllocationListColumns::columns());
    }
}
