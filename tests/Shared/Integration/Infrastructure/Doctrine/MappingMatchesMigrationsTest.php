<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The test database is built from the migrations. The schema diff against the mapping, which is what
 * `doctrine:schema:update` and `doctrine:migrations:diff` produce, must be empty (#1398): otherwise every generated
 * migration starts with statements nobody wants, some of which would drop indexes the application relies on.
 *
 * When this fails after a change, either the mapping or a new migration is missing; see "Migrations" in
 * docs/03-development/development-workflow.md.
 */
#[ResetDatabase]
final class MappingMatchesMigrationsTest extends KernelTestCase
{
    /**
     * The inpatient stay test bundle references hospital, import and encounter by id only, so that it uses no entity
     * of the application; its foreign keys exist only in its migration. DBAL adds an implicit index for the
     * hospital key while comparing, which goes with the keys.
     */
    private const array ACCEPTED = [
        'ALTER TABLE test_inpatient_stay DROP CONSTRAINT fk_test_inpatient_stay_encounter',
        'ALTER TABLE test_inpatient_stay DROP CONSTRAINT fk_test_inpatient_stay_hospital',
        'ALTER TABLE test_inpatient_stay DROP CONSTRAINT fk_test_inpatient_stay_import',
        'DROP INDEX IDX_D4242F7663DBB69',
    ];

    public function testTheSchemaDiffAgainstTheMigratedDatabaseIsEmpty(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $statements = new SchemaTool($entityManager)->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata());
        // Doctrine Migrations hides its own table in its console commands only.
        $statements = array_values(array_filter($statements, static fn (string $sql): bool => 'DROP TABLE doctrine_migration_versions' !== $sql));

        self::assertEqualsCanonicalizing(self::ACCEPTED, $statements);
    }
}
