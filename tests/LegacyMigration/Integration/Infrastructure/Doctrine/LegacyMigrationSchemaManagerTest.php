<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Integration\Infrastructure\Doctrine;

use App\LegacyMigration\Infrastructure\Doctrine\LegacyMigrationSchemaManager;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LegacyMigrationSchemaManagerTest extends KernelTestCase
{
    private LegacyMigrationSchemaManager $schemaManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->schemaManager = self::getContainer()->get(LegacyMigrationSchemaManager::class);
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->schemaManager->uninstall(true);
    }

    public function testInstallIsIdempotent(): void
    {
        $this->schemaManager->install();
        $this->schemaManager->install();

        self::assertTrue($this->schemaManager->isInstalled());
    }

    public function testUninstallDropsOnlyAllowlistedTables(): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS keep_me (id INT PRIMARY KEY)');
        $this->schemaManager->install();
        $this->schemaManager->uninstall(true);

        $tables = $this->connection->createSchemaManager()->listTableNames();
        self::assertContains('keep_me', $tables);
        self::assertNotContains('legacy_migration_user_mapping', $tables);
    }

    public function testIsInstalledWorks(): void
    {
        self::assertFalse($this->schemaManager->isInstalled());
        $this->schemaManager->install();
        self::assertTrue($this->schemaManager->isInstalled());
    }
}

