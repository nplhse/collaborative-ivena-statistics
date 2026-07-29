<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use PHPUnit\Framework\TestCase;

final class CatalogDimensionKeyTest extends TestCase
{
    public function testProjectionColumnsAreStable(): void
    {
        self::assertSame('secondary_transport_id', CatalogDimensionKey::SecondaryTransport->projectionColumn());
        self::assertSame('indication_normalized_id', CatalogDimensionKey::Indication->projectionColumn());
        self::assertSame('department_id', CatalogDimensionKey::Department->projectionColumn());
        self::assertSame('dispatch_area_id', CatalogDimensionKey::DispatchArea->projectionColumn());
        self::assertSame('state_id', CatalogDimensionKey::State->projectionColumn());
        self::assertSame('hospital_id', CatalogDimensionKey::Hospital->projectionColumn());
    }
}
