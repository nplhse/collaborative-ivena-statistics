<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

final readonly class ReferenceYamlPaths
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    public function path(string $filename): string
    {
        return $this->projectDir.'/fixtures/reference/'.$filename;
    }
}
