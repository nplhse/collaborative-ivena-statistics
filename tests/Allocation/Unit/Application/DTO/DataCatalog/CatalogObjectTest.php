<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\DTO\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\CatalogGroup;
use App\Allocation\Application\DTO\DataCatalog\CatalogObject;
use App\Allocation\Application\DTO\DataCatalog\CatalogVariable;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogType;
use PHPUnit\Framework\TestCase;

final class CatalogObjectTest extends TestCase
{
    public function testHasLocalTimeOnlyWithADateTimeOrTimeVariable(): void
    {
        self::assertFalse($this->object(DataCatalogType::INTEGER, DataCatalogType::DATE)->hasLocalTime());
        self::assertTrue($this->object(DataCatalogType::INTEGER, DataCatalogType::DATETIME)->hasLocalTime());
        self::assertTrue($this->object(DataCatalogType::TIME)->hasLocalTime());
    }

    private function object(DataCatalogType ...$types): CatalogObject
    {
        $variables = [];
        foreach ($types as $i => $type) {
            $variables[] = new CatalogVariable('test.v'.$i, 'allocation', $type, false, 'test', 'v'.$i);
        }

        return new CatalogObject('test', \stdClass::class, 'allocation', 'tabler:database', 'test', false, [new CatalogGroup('time', $variables)]);
    }
}
