<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\DataCatalog;

use App\Allocation\Application\Explore\DataCatalog\GlossaryReference;
use PHPUnit\Framework\TestCase;

final class GlossaryReferenceTest extends TestCase
{
    public function testMatchesRouteAndParametersOnly(): void
    {
        $reference = new GlossaryReference('app_explore_glossary_show', ['slug' => 'hospital-classifications'], 'tier');

        self::assertTrue($reference->isFor('app_explore_glossary_show', ['slug' => 'hospital-classifications']));
        self::assertFalse($reference->isFor('app_explore_glossary_show', ['slug' => 'urgency']));
        self::assertFalse($reference->isFor('app_explore_glossary_mts', []));
    }
}
