<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Infrastructure;

use App\DataFixtures\Pattern\Dto\DistributionPattern;
use Symfony\Component\Yaml\Yaml;

final readonly class PatternYamlSerializer
{
    public function __construct(
        private PatternYamlPaths $paths,
    ) {
    }

    public function load(string $patternName): DistributionPattern
    {
        $manifest = $this->loadManifest();
        if (!isset($manifest['patterns'][$patternName]) || !\is_array($manifest['patterns'][$patternName])) {
            throw new \RuntimeException(sprintf('Unknown distribution pattern "%s".', $patternName));
        }

        /** @var array{file: string} $entry */
        $entry = $manifest['patterns'][$patternName];
        $path = $this->paths->patternPath($entry['file']);
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Pattern file not found: %s', $path));
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($path);

        return DistributionPattern::fromArray($patternName, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadManifest(): array
    {
        $path = $this->paths->manifestPath();
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Pattern manifest not found: %s', $path));
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($path);

        return $data;
    }

    /**
     * @return list<string>
     */
    public function listPatternNames(): array
    {
        $manifest = $this->loadManifest();
        $patterns = $manifest['patterns'] ?? [];
        if (!\is_array($patterns)) {
            return [];
        }

        /* @var list<string> */
        return array_keys($patterns);
    }

    public function savePattern(string $filename, DistributionPattern $pattern): void
    {
        $directory = $this->paths->directory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create pattern directory: %s', $directory));
        }

        $path = $this->paths->patternPath($filename);
        $yaml = Yaml::dump($pattern->toArray(), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        file_put_contents($path, $yaml);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function saveManifest(array $manifest): void
    {
        $directory = $this->paths->directory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create pattern directory: %s', $directory));
        }

        $yaml = Yaml::dump($manifest, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        file_put_contents($this->paths->manifestPath(), $yaml);
    }
}
