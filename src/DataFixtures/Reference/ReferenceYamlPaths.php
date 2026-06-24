<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReferenceYamlPaths
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function path(string $filename): string
    {
        return $this->projectDir.'/fixtures/reference/'.$filename;
    }
}
