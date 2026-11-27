<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The test database is built from the migrations. A schema diff drops every table it sees that no entity maps,
 * so after the schema filter every remaining table must be mapped (#1372): tables managed with DBAL only are
 * registered through DbalManagedTablesInterface. A new DBAL table without registration fails here.
 *
 * Compares table names directly instead of running SchemaTool, which is what `doctrine:schema:update` and
 * `doctrine:migrations:diff` do. MappingMatchesMigrationsTest checks the other differences (#1398).
 */
#[ResetDatabase]
final class SchemaDiffDropsNoTableTest extends KernelTestCase
{
    /**
     * Tables that other libraries manage: Doctrine Migrations hides its own table in its console commands only,
     * and Symfony adds the Messenger transport table to the target schema through a schema listener.
     */
    private const array LIBRARY_TABLES = ['doctrine_migration_versions', 'messenger_messages'];

    public function testEveryTableTheSchemaFilterShowsIsMapped(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $mapped = self::LIBRARY_TABLES;
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $mapped[] = $metadata->getTableName();
            foreach ($metadata->getAssociationMappings() as $association) {
                if ($association instanceof ManyToManyOwningSideMapping) {
                    $mapped[] = $association->joinTable->name;
                }
            }
        }

        // listTableNames() applies the connection's schema asset filter, like the diff does.
        $tables = $entityManager->getConnection()->createSchemaManager()->listTableNames();
        $normalize = static fn (string $name): string => strtolower(str_replace('"', '', $name));
        $mapped = array_map($normalize, $mapped);

        self::assertSame([], array_values(array_filter($tables, static fn (string $table): bool => !\in_array($normalize($table), $mapped, true))));
    }
}
