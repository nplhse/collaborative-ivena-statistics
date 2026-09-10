<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference\Support;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogReader;
use App\DataFixtures\Reference\ReferenceYamlLoader;

trait CreatesReferenceYamlLoader
{
    private function referenceYamlLoader(): ReferenceYamlLoader
    {
        return new ReferenceYamlLoader(new ReferenceCatalogReader(\dirname(__DIR__, 4)));
    }
}
