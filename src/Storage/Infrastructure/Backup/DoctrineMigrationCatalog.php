<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Contract\MigrationCatalogInterface;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The migrations of all configured migration paths, plugins included (ADR 037).
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for MigrationCatalogInterface.
 */
#[AsAlias(MigrationCatalogInterface::class)]
final readonly class DoctrineMigrationCatalog implements MigrationCatalogInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $dependencyFactory,
    ) {
    }

    #[\Override]
    public function available(): array
    {
        $versions = [];
        foreach ($this->dependencyFactory->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $versions[] = (string) $migration->getVersion();
        }

        return $versions;
    }
}
