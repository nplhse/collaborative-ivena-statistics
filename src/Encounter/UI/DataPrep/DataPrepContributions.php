<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\Contract\DataPrep\DataPrepCatalog;
use App\Encounter\Contract\DataPrep\DataPrepContributionInterface;
use App\Encounter\Contract\DataPrep\DataPrepLocalMap;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The schema contributions of the data preparation tool (#1301), ordered by schema name so that the registration
 * order of bundles never decides anything. Validates what they contribute when it is read: files exist, profiles
 * name their schema, profile ids and catalog ids are unique, schema files carry no key rules.
 */
final readonly class DataPrepContributions
{
    /** @var list<DataPrepContributionInterface> */
    private array $contributions;

    /**
     * @param iterable<DataPrepContributionInterface> $contributions
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(DataPrepContributionInterface::TAG)]
        iterable $contributions,
        private EncounterDimensionRegistry $dimensions,
    ) {
        $sorted = [];
        foreach ($contributions as $contribution) {
            if (isset($sorted[$contribution->schema()])) {
                throw new \LogicException(sprintf('Two data preparation contributions for schema "%s".', $contribution->schema()));
            }
            $sorted[$contribution->schema()] = $contribution;
        }
        ksort($sorted);
        $this->contributions = array_values($sorted);
    }

    public function isEmpty(): bool
    {
        return [] === $this->contributions;
    }

    /**
     * Schema files with the key rules of their version from the dimension, by schema name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        $schemas = [];
        foreach ($this->contributions as $contribution) {
            $schema = $this->json($contribution->schemaFile());
            if (isset($schema['keys'])) {
                throw new \LogicException(sprintf('Schema file "%s" must not contain key rules; they come from the dimension.', $contribution->schemaFile()));
            }
            $version = $schema['version'] ?? null;
            if (($schema['schema'] ?? null) !== $contribution->schema() || !\is_int($version)) {
                throw new \LogicException(sprintf('Schema file "%s" does not describe a version of schema "%s".', $contribution->schemaFile(), $contribution->schema()));
            }
            $schemas[$contribution->schema()] = [...$schema, 'keys' => $this->dimensions->get($contribution->schema())->keyRules($version)->toArray()];
        }

        return $schemas;
    }

    /**
     * Source profiles of every schema, ordered by schema and profile id.
     *
     * @return list<array<string, mixed>>
     */
    public function profiles(): array
    {
        $profiles = [];
        foreach ($this->contributions as $contribution) {
            $own = [];
            foreach ($contribution->profileFiles() as $file) {
                $profile = $this->json($file);
                $id = $profile['id'] ?? null;
                if (($profile['schema'] ?? null) !== $contribution->schema() || !\is_string($id)) {
                    throw new \LogicException(sprintf('Profile "%s" does not belong to schema "%s".', $file, $contribution->schema()));
                }
                if (isset($profiles[$id]) || isset($own[$id])) {
                    throw new \LogicException(sprintf('Two data preparation profiles with id "%s".', $id));
                }
                $own[$id] = $profile;
            }
            ksort($own);
            $profiles = [...$profiles, ...$own];
        }

        return array_values($profiles);
    }

    /**
     * Script modules of the schemas that have one, in schema order.
     *
     * @return list<string>
     */
    public function scripts(): array
    {
        $scripts = [];
        foreach ($this->contributions as $contribution) {
            $file = $contribution->scriptFile();
            if (null !== $file) {
                $scripts[] = $this->read($file);
            }
        }

        return $scripts;
    }

    /**
     * @return list<DataPrepCatalog>
     */
    public function catalogs(): array
    {
        $catalogs = [];
        foreach ($this->contributions as $contribution) {
            foreach ($contribution->catalogs() as $catalog) {
                if (isset($catalogs[$catalog->id])) {
                    throw new \LogicException(sprintf('Two data preparation catalogs with id "%s".', $catalog->id));
                }
                $catalogs[$catalog->id] = $catalog;
            }
        }

        return array_values($catalogs);
    }

    /**
     * The local map of the tool: at most one schema may define one, the page and its files are about it.
     */
    public function localMap(): ?DataPrepLocalMap
    {
        $maps = array_values(array_filter(array_map(static fn (DataPrepContributionInterface $contribution): ?DataPrepLocalMap => $contribution->localMap(), $this->contributions)));
        if (\count($maps) > 1) {
            throw new \LogicException('Only one data preparation contribution may define a local map.');
        }

        return $maps[0] ?? null;
    }

    public function read(string $file): string
    {
        $content = is_file($file) ? file_get_contents($file) : false;
        if (false === $content) {
            throw new \RuntimeException(sprintf('Cannot read data preparation file "%s".', $file));
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $file): array
    {
        /* @var array<string, mixed> */
        return json_decode($this->read($file), true, 512, \JSON_THROW_ON_ERROR);
    }
}
