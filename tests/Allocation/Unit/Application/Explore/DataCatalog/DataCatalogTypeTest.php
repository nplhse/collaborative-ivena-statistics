<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\DataCatalog;

use App\Allocation\Application\Explore\DataCatalog\DataCatalogType;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataCatalogTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, DataCatalogType}>
     */
    public static function doctrineTypes(): iterable
    {
        yield 'enum wins over its column type' => [Types::INTEGER, true, DataCatalogType::CODE];
        yield 'smallint' => [Types::SMALLINT, false, DataCatalogType::INTEGER];
        yield 'decimal' => [Types::DECIMAL, false, DataCatalogType::DECIMAL];
        yield 'float' => [Types::FLOAT, false, DataCatalogType::DECIMAL];
        yield 'datetimetz' => [Types::DATETIMETZ_IMMUTABLE, false, DataCatalogType::DATETIME];
        yield 'date' => [Types::DATE_IMMUTABLE, false, DataCatalogType::DATE];
        yield 'time' => [Types::TIME_IMMUTABLE, false, DataCatalogType::TIME];
        yield 'boolean' => [Types::BOOLEAN, false, DataCatalogType::BOOLEAN];
        yield 'string' => [Types::STRING, false, DataCatalogType::TEXT];
    }

    #[DataProvider('doctrineTypes')]
    public function testMapsDoctrineTypes(string $type, bool $hasEnum, DataCatalogType $expected): void
    {
        self::assertSame($expected, DataCatalogType::fromDoctrine($type, $hasEnum));
    }

    public function testEveryTypeHasALocalIcon(): void
    {
        $icons = [];
        foreach (DataCatalogType::cases() as $type) {
            $icon = $type->icon();
            self::assertStringStartsWith('tabler:', $icon, $type->value);
            self::assertFileExists(\dirname(__DIR__, 6).'/assets/icons/tabler/'.substr($icon, 7).'.svg', $type->value);
            $icons[] = $icon;
        }

        self::assertSame($icons, array_values(array_unique($icons)), 'Each type has its own icon.');
    }

    public function testOnlyDateTimesAndTimesAreLocalTime(): void
    {
        $local = array_values(array_filter(DataCatalogType::cases(), static fn (DataCatalogType $type): bool => $type->isLocalTime()));

        self::assertSame([DataCatalogType::DATETIME, DataCatalogType::TIME], $local);
    }

    public function testLabelKeyFollowsTheValue(): void
    {
        self::assertSame('data_catalog.type.reference', DataCatalogType::REFERENCE->labelKey());
    }
}
