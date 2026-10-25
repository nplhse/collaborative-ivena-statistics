<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Twig;

use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Allocation\UI\Twig\IndicationNormalizedListColumns;
use App\Allocation\UI\Twig\IndicationRawListColumns;
use App\Shared\UI\Twig\DataTable\DataTableColumn;
use PHPUnit\Framework\TestCase;

final class IndicationListColumnsTest extends TestCase
{
    public function testRawDefaultViewKeepsTheFormerColumns(): void
    {
        $schema = new IndicationRawListColumns()->preferenceSchema();

        self::assertSame(IndicationRawListColumns::PREFERENCE_KEY, $schema->key);
        self::assertSame(['code', 'name', 'reviewStatus', 'normalized', 'lastChange', 'lastChangedBy'], $schema->defaultVisibleColumns);
        self::assertSame(['name'], $schema->requiredColumns);
        self::assertSame('code', $schema->defaultSortBy);
        self::assertSame('asc', $schema->defaultOrderBy);
    }

    public function testNormalizedDefaultViewKeepsTheFormerColumns(): void
    {
        $schema = new IndicationNormalizedListColumns()->preferenceSchema();

        self::assertSame(IndicationNormalizedListColumns::PREFERENCE_KEY, $schema->key);
        self::assertSame(['code', 'name', 'lastChange', 'lastChangedBy'], $schema->defaultVisibleColumns);
        self::assertSame(['name'], $schema->requiredColumns);
    }

    public function testSortableColumnsMatchTheSortKeys(): void
    {
        self::assertEqualsCanonicalizing(IndicationRawListColumns::SORT_KEYS, $this->sortKeys(IndicationRawListColumns::columns()));
        self::assertEqualsCanonicalizing(IndicationNormalizedListColumns::SORT_KEYS, $this->sortKeys(IndicationNormalizedListColumns::columns()));
    }

    public function testEverySortKeyHasItsOwnDatabaseField(): void
    {
        $raw = array_map(IndicationRawRepository::listSortField(...), IndicationRawListColumns::SORT_KEYS);
        $normalized = array_map(IndicationNormalizedRepository::listSortField(...), IndicationNormalizedListColumns::SORT_KEYS);

        self::assertSame($raw, array_values(array_unique($raw)));
        self::assertSame($normalized, array_values(array_unique($normalized)));
    }

    /**
     * @param list<array<string, mixed>> $columns
     *
     * @return list<string>
     */
    private function sortKeys(array $columns): array
    {
        return array_values(array_map(
            static fn (DataTableColumn $column): string => $column->resolvedSortKey(),
            array_filter(
                array_map(DataTableColumn::fromArray(...), $columns),
                static fn (DataTableColumn $column): bool => $column->sortable,
            ),
        ));
    }
}
