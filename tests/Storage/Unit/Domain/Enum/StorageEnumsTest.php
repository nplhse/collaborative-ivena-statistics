<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Domain\Enum;

use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\StorageCategory;
use PHPUnit\Framework\TestCase;

final class StorageEnumsTest extends TestCase
{
    public function testLevelsRankInCaseOrderAndUnknownHasNoRank(): void
    {
        $ranks = [];
        foreach (CapacityLevel::cases() as $level) {
            if ($level->isKnown()) {
                $ranks[] = $level->rank();
            }
        }

        self::assertSame([0, 1, 2, 3, 4], $ranks);
        self::assertNull(CapacityLevel::Unknown->rank());
        self::assertFalse(CapacityLevel::Unknown->isKnown());
    }

    public function testCategoriesHaveDistinctColorsAndLabels(): void
    {
        $colors = array_map(static fn (StorageCategory $category): string => $category->color(), StorageCategory::cases());

        self::assertCount(6, $colors);
        self::assertSame($colors, array_unique($colors));
        foreach ($colors as $color) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color);
        }
        self::assertSame('storage.category.temporary', StorageCategory::Temporary->labelKey());
    }
}
