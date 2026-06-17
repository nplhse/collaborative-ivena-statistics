<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use Symfony\Component\Yaml\Yaml;

final readonly class ReferenceYamlLoader
{
    public function __construct(
        private ReferenceYamlPaths $paths,
    ) {
    }

    /**
     * @return list<array{state: string, name: string}>
     */
    public function areas(): array
    {
        /** @var array{areas: list<array{state: string, name: string}>} $data */
        $data = $this->load('areas.yaml');

        return $data['areas'];
    }

    /**
     * @return list<string>
     */
    public function names(string $filename): array
    {
        /** @var array{names: list<string>} $data */
        $data = $this->load($filename);

        return $data['names'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function hospitals(): array
    {
        /** @var array{hospitals: list<array<string, mixed>>} $data */
        $data = $this->load('hospitals.yaml');

        return $data['hospitals'];
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function indicationsNormalized(): array
    {
        /** @var array{indications: list<array{code: string, name: string}>} $data */
        $data = $this->load('indications_normalized.yaml');

        return $data['indications'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function indicationsRaw(): array
    {
        /** @var array{indications: list<array<string, mixed>>} $data */
        $data = $this->load('indications_raw.yaml');

        return $data['indications'];
    }

    /**
     * @return list<array{name: string, category: ?string, codes: list<string>}>
     */
    public function indicationGroups(): array
    {
        /** @var array{indication_groups: list<array{name: string, category?: ?string, codes: list<string>}>} $data */
        $data = $this->load('indication_groups.yaml');

        return array_map(
            static fn (array $row): array => [
                'name' => $row['name'],
                'category' => $row['category'] ?? null,
                'codes' => array_map(strval(...), $row['codes']),
            ],
            $data['indication_groups'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $filename): array
    {
        $path = $this->paths->path($filename);
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Reference fixture file not found: %s', $path));
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($path);

        return $data;
    }
}
