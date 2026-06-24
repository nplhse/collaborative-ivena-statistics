<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PatternYamlPaths
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function directory(): string
    {
        return $this->projectDir.'/fixtures/patterns';
    }

    public function manifestPath(): string
    {
        return $this->directory().'/manifest.yaml';
    }

    public function patternPath(string $filename): string
    {
        return $this->directory().'/'.$filename;
    }
}
