<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\DataTable;

use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableColumnType;
use PHPUnit\Framework\TestCase;

final class DataTableColumnTest extends TestCase
{
    public function testFromArrayHydratesTypeOptionsAndVisibility(): void
    {
        $column = DataTableColumn::fromArray([
            'key' => 'location',
            'label' => 'label.location',
            'type' => 'badge',
            'property' => 'location',
            'sortable' => true,
            'sortKey' => 'location',
            'width' => 'col-1',
            'visible' => false,
            'badgePalette' => 'hospital_location',
            'numberProperty' => 'beds',
        ]);

        self::assertSame('location', $column->key);
        self::assertSame(DataTableColumnType::Badge, $column->type);
        self::assertSame('location', $column->resolvedSortKey());
        self::assertFalse($column->visible);
        self::assertSame('hospital_location', $column->option('badgePalette'));
        self::assertSame('beds', $column->option('numberProperty'));
    }

    public function testUnknownTypeFallsBackToText(): void
    {
        $column = DataTableColumn::fromArray([
            'key' => 'name',
            'type' => 'not-a-type',
        ]);

        self::assertSame(DataTableColumnType::Text, $column->type);
        self::assertSame('name', $column->property);
    }

    public function testFromMixedReturnsExistingColumn(): void
    {
        $column = new DataTableColumn('name', 'Name');

        self::assertSame($column, DataTableColumn::fromMixed($column));
    }

    public function testMissingKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DataTableColumn::fromArray(['label' => 'Name']);
    }
}
