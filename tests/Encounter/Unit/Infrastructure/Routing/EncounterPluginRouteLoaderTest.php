<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Infrastructure\Routing;

use App\Encounter\Contract\Plugin\EncounterPluginBundleInterface;
use App\Encounter\Infrastructure\Routing\EncounterPluginRouteLoader;
use PHPUnit\Framework\TestCase;

final class EncounterPluginRouteLoaderTest extends TestCase
{
    public function testSupportsOnlyItsType(): void
    {
        $loader = new EncounterPluginRouteLoader([], []);

        self::assertTrue($loader->supports('.', 'encounter_plugins'));
        self::assertFalse($loader->supports('.', 'attribute'));
    }

    public function testSkipsUnmarkedBundlesAndMarkedBundlesWithoutRouteFile(): void
    {
        $marked = new class implements EncounterPluginBundleInterface {};
        $loader = new EncounterPluginRouteLoader(
            ['MarkedBundle' => $marked::class, 'OtherBundle' => \stdClass::class],
            [
                'MarkedBundle' => ['path' => sys_get_temp_dir().'/no-such-bundle-'.bin2hex(random_bytes(4)), 'namespace' => 'Marked'],
                'OtherBundle' => ['path' => __DIR__, 'namespace' => 'Other'],
            ],
        );

        self::assertCount(0, $loader->load('.', 'encounter_plugins'));
    }
}
