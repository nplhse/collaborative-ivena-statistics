<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\DataTable;

use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableValueResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DataTableValueResolverTest extends TestCase
{
    public function testReadsObjectPropertyWithFallback(): void
    {
        $row = new DataTableValueResolverTestRow(null, new \DateTimeImmutable('2024-01-02 10:00:00'));
        $column = DataTableColumn::fromArray([
            'key' => 'lastChange',
            'property' => 'updatedAt',
            'fallbackProperty' => 'createdAt',
        ]);

        $value = new DataTableValueResolver()->resolve($row, $column);

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2024-01-02', $value->format('Y-m-d'));
    }

    public function testReadsArrayAndNestedPaths(): void
    {
        $resolver = new DataTableValueResolver();
        $row = [
            'hospital' => 'Kiel',
            'hospital_public_id' => 'abc',
        ];

        self::assertSame('Kiel', $resolver->read($row, 'hospital'));
        self::assertSame('abc', $resolver->read($row, 'hospital_public_id'));
    }

    public function testScalarConvertsUuidAndEnum(): void
    {
        $resolver = new DataTableValueResolver();
        $uuid = Uuid::v4();

        self::assertSame($uuid->toRfc4122(), $resolver->scalar($uuid));
        self::assertSame('Urban', $resolver->scalar(DataTableBadgePaletteTestEnum::Urban));
        self::assertSame('Urban', $resolver->enumValue(DataTableBadgePaletteTestEnum::Urban));
    }

    public function testBooleanFalseIsAPresentCellValue(): void
    {
        $column = DataTableColumn::fromArray([
            'key' => 'participating',
            'property' => 'participating',
            'fallbackProperty' => 'name',
        ]);

        $value = new DataTableValueResolver()->resolve(
            ['participating' => false, 'name' => 'fallback'],
            $column,
        );

        self::assertFalse($value);
    }
}

final class DataTableValueResolverTestRow
{
    public function __construct(
        public ?\DateTimeImmutable $updatedAt,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
