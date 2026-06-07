<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Application\CaseFlowGeoKeyResolver;
use PHPUnit\Framework\TestCase;

final class CaseFlowGeoKeyResolverTest extends TestCase
{
    public function testResolvesMappedDispatchAreaName(): void
    {
        $configPath = \dirname(__DIR__, 4).'/config/case_flow/dispatch_area_geo_map.yaml';
        $resolver = new CaseFlowGeoKeyResolver($configPath);

        self::assertSame('frankfurt', $resolver->resolve(1, 'Frankfurt'));
    }

    public function testResolvesCaseInsensitiveMappedName(): void
    {
        $configPath = \dirname(__DIR__, 4).'/config/case_flow/dispatch_area_geo_map.yaml';
        $resolver = new CaseFlowGeoKeyResolver($configPath);

        self::assertSame('frankfurt', $resolver->resolve(1, 'frankfurt'));
    }

    public function testSlugifiesUnknownDispatchAreaName(): void
    {
        $configPath = \dirname(__DIR__, 4).'/config/case_flow/dispatch_area_geo_map.yaml';
        $resolver = new CaseFlowGeoKeyResolver($configPath);

        self::assertSame('unknown-area', $resolver->resolve(99, 'Unknown Area'));
    }

    public function testReturnsEmptyMappingWhenConfigMissing(): void
    {
        $resolver = new CaseFlowGeoKeyResolver('/tmp/non-existent-case-flow-geo-map.yaml');

        self::assertSame('some-area', $resolver->resolve(1, 'Some Area'));
    }
}
