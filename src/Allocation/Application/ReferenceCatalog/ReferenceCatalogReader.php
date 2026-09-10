<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class ReferenceCatalogReader
{
    public const string DEFAULT_RELATIVE_PATH = 'fixtures/reference/catalog.yaml';

    private ?ReferenceCatalogDocument $defaultDocument = null;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function defaultPath(): string
    {
        return $this->projectDir.'/'.self::DEFAULT_RELATIVE_PATH;
    }

    public function loadDefault(): ReferenceCatalogDocument
    {
        return $this->defaultDocument ??= $this->load($this->defaultPath());
    }

    public function load(string $path): ReferenceCatalogDocument
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Reference catalog file not found: %s', $path));
        }

        $data = Yaml::parseFile($path);
        if (!\is_array($data)) {
            throw new \RuntimeException(sprintf('Reference catalog file is not a YAML mapping: %s', $path));
        }

        return ReferenceCatalogDocument::fromArray($data);
    }
}
