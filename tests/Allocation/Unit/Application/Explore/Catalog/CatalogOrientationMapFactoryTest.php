<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Explore\Catalog\CatalogOrientationMapFactory;
use PHPUnit\Framework\TestCase;

final class CatalogOrientationMapFactoryTest extends TestCase
{
    private CatalogOrientationMapFactory $factory;

    protected function setUp(): void
    {
        $configPath = \dirname(__DIR__, 6).'/config/case_flow/dispatch_area_geo_map.yaml';
        self::assertFileExists($configPath);
        $this->factory = new CatalogOrientationMapFactory($configPath);
    }

    public function testDispatchAreaResolvesKnownHessenKey(): void
    {
        $map = $this->factory->forDispatchArea('Frankfurt');

        self::assertTrue($map->enabled);
        self::assertSame('frankfurt', $map->highlightKey);
        self::assertFalse($map->showAllAreas);
    }

    public function testDispatchAreaIsDisabledForUnknownName(): void
    {
        $map = $this->factory->forDispatchArea('Unknown Area XYZ');

        self::assertFalse($map->enabled);
        self::assertNull($map->highlightKey);
    }

    public function testStateEnablesFullHessenMap(): void
    {
        $map = $this->factory->forState('Hessen');

        self::assertTrue($map->enabled);
        self::assertTrue($map->showAllAreas);
        self::assertNull($map->highlightKey);
    }

    public function testStateIsDisabledOutsideHessenPilot(): void
    {
        $map = $this->factory->forState('Bayern');

        self::assertFalse($map->enabled);
    }
}
