<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference\Support;

use App\DataFixtures\Reference\ReferenceYamlLoader;
use App\DataFixtures\Reference\ReferenceYamlPaths;

trait CreatesReferenceYamlLoader
{
    private function referenceYamlLoader(): ReferenceYamlLoader
    {
        return new ReferenceYamlLoader(new ReferenceYamlPaths(dirname(__DIR__, 4)));
    }
}
