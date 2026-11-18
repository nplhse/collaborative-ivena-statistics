<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Database;

use App\Shared\Application\Database\DbalManagedTablesInterface;

/**
 * Tables that track runs of the synthetic data generator, written and read with DBAL; no entity maps them
 * (#1372). Like all of DataFixtures, registered in dev, test and demo only, where migrations are generated.
 *
 * @psalm-suppress UnusedClass Tagged via DbalManagedTablesInterface.
 */
final readonly class GenerationRunDbalTables implements DbalManagedTablesInterface
{
    #[\Override]
    public function tables(): array
    {
        return ['generation_run', 'generation_run_hospital'];
    }
}
