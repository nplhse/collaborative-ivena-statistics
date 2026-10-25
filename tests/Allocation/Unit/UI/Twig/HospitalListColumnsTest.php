<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Twig;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\UI\Twig\HospitalListColumns;
use App\Shared\UI\Twig\DataTable\DataTableColumn;
use PHPUnit\Framework\TestCase;

final class HospitalListColumnsTest extends TestCase
{
    public function testDefaultViewKeepsTheFormerColumnsAndHidesTheNewOnes(): void
    {
        $schema = new HospitalListColumns()->preferenceSchema();

        self::assertSame(HospitalListColumns::PREFERENCE_KEY, $schema->key);
        self::assertSame(
            ['participating', 'name', 'dispatchArea', 'state', 'location', 'tier', 'size', 'lastChange'],
            $schema->defaultVisibleColumns,
        );
        self::assertSame(['name'], $schema->requiredColumns);
        self::assertSame('name', $schema->defaultSortBy);
        self::assertSame('asc', $schema->defaultOrderBy);
    }

    public function testSortableColumnsAndSortKeysMatch(): void
    {
        $columns = array_map(DataTableColumn::fromArray(...), HospitalListColumns::columns());
        $sortKeys = array_values(array_map(
            static fn (DataTableColumn $column): string => $column->resolvedSortKey(),
            array_filter($columns, static fn (DataTableColumn $column): bool => $column->sortable),
        ));

        self::assertEqualsCanonicalizing(HospitalListColumns::SORT_KEYS, $sortKeys);
    }

    public function testEverySortKeyHasItsOwnDatabaseField(): void
    {
        $fields = array_map(HospitalRepository::hospitalListSortField(...), HospitalListColumns::SORT_KEYS);

        self::assertSame($fields, array_values(array_unique($fields)), 'Each sort key must map to a distinct field instead of falling back to the name.');
    }

    public function testOnlyTheParticipationIconIsNeitherConfigurableNorRequired(): void
    {
        $fixed = array_values(array_filter(
            array_map(DataTableColumn::fromArray(...), HospitalListColumns::columns()),
            static fn (DataTableColumn $column): bool => !$column->configurable && !$column->required,
        ));

        self::assertCount(1, $fixed);
        self::assertSame('participating', $fixed[0]->key);
    }
}
