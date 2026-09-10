<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

use Symfony\Component\Yaml\Yaml;

final class ReferenceCatalogWriter
{
    private const string HEADER = <<<'TXT'
# Allocation reference catalog (single file).
# Used by Doctrine fixtures and app:reference:import / export.
# Schema: docs/04-features/import/reference-catalog-yaml.md

TXT;

    public function dump(ReferenceCatalogDocument $document): string
    {
        $flags = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            | Yaml::DUMP_NULL_AS_TILDE
            | Yaml::DUMP_COMPACT_NESTED_MAPPING
            | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

        return self::HEADER.Yaml::dump($document->toArray(), 8, 2, $flags);
    }

    public function write(string $path, ReferenceCatalogDocument $document): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        if (false === file_put_contents($path, $this->dump($document))) {
            throw new \RuntimeException(sprintf('Unable to write catalog file: %s', $path));
        }
    }
}
