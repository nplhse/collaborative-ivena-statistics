<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\Database\DbalManagedTablesInterface;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\NamedObject;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Schema asset filter of the default connection (#1372): hides the tables the contexts manage with DBAL
 * (DbalManagedTablesInterface) and their sequences from schema introspection. `doctrine:migrations:diff` and
 * `doctrine:schema:update` then no longer propose to drop them.
 *
 * Doctrine passes names or asset objects of tables and sequences, with or without the default schema `public`.
 *
 * @psalm-suppress UnusedClass Tagged as doctrine.dbal.schema_filter.
 */
#[AutoconfigureTag('doctrine.dbal.schema_filter', ['connection' => 'default'])]
final class DbalManagedTablesSchemaFilter
{
    /** @var array<string, true>|null */
    private ?array $tables = null;

    /**
     * @param iterable<DbalManagedTablesInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(DbalManagedTablesInterface::TAG)]
        private readonly iterable $providers = [],
    ) {
    }

    /**
     * @param string|AbstractAsset<Name> $asset
     */
    public function __invoke(string|AbstractAsset $asset): bool
    {
        if (\is_string($asset)) {
            $name = $asset;
        } elseif ($asset instanceof NamedObject) {
            $name = $asset->getObjectName()->toString();
        } else {
            return true;
        }

        $name = strtolower(str_replace('"', '', $name));
        if (str_starts_with($name, 'public.')) {
            $name = substr($name, \strlen('public.'));
        }

        $tables = $this->tables();
        if (isset($tables[$name])) {
            return false;
        }

        // Sequences of the tables' serial and identity columns, for example `closure_event_id_seq`.
        if (str_ends_with($name, '_seq')) {
            foreach (array_keys($tables) as $table) {
                if (str_starts_with($name, $table.'_')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, true>
     */
    private function tables(): array
    {
        if (null === $this->tables) {
            $this->tables = [];
            foreach ($this->providers as $provider) {
                foreach ($provider->tables() as $table) {
                    $this->tables[strtolower($table)] = true;
                }
            }
        }

        return $this->tables;
    }
}
