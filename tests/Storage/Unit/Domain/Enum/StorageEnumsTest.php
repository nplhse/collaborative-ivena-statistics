<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Domain\Enum;

use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{StorageCategory, string, string}>
     */
    public static function categories(): iterable
    {
        yield 'database' => [StorageCategory::Database, '#206bc4', 'fas fa-database'];
        yield 'imports' => [StorageCategory::Imports, '#2fb344', 'fas fa-file-import'];
        yield 'media' => [StorageCategory::Media, '#f76707', 'fas fa-photo-film'];
        yield 'application' => [StorageCategory::Application, '#ae3ec9', 'fas fa-code'];
        yield 'temporary' => [StorageCategory::Temporary, '#f59f00', 'fas fa-broom'];
        yield 'other' => [StorageCategory::Other, '#868e96', 'fas fa-box-archive'];
    }

    #[DataProvider('categories')]
    public function testCategoryHasColorIconAndLabel(StorageCategory $category, string $color, string $icon): void
    {
        self::assertSame($color, $category->color());
        self::assertSame($icon, $category->icon());
        self::assertSame('storage.category.'.$category->value, $category->labelKey());
    }

    public function testCategoriesAreDistinguishable(): void
    {
        $colors = array_map(static fn (StorageCategory $category): string => $category->color(), StorageCategory::cases());
        $icons = array_map(static fn (StorageCategory $category): string => $category->icon(), StorageCategory::cases());

        self::assertCount(6, array_unique($colors));
        self::assertCount(6, array_unique($icons));
    }

    public function testLabelKeysFollowTheEnumValues(): void
    {
        foreach (CapacityLevel::cases() as $level) {
            self::assertSame('storage.level.'.$level->value, $level->labelKey());
        }

        foreach (CapacitySource::cases() as $source) {
            self::assertSame('storage.capacity_source.'.$source->value, $source->labelKey());
        }

        foreach (StorageLocation::cases() as $location) {
            self::assertSame('storage.location.'.$location->value, $location->labelKey());
        }
    }
}
