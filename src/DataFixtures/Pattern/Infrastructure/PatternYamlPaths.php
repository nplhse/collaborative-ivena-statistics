<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Infrastructure;

final readonly class PatternYamlPaths
{
    public function __construct(
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
