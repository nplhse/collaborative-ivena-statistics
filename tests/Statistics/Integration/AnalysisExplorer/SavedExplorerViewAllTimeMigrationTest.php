<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * #1035: saved explorer views that stored `all_time` (whole history) are rewritten to `all`.
 * Every other period value stays as it is.
 */
#[ResetDatabase]
final class SavedExplorerViewAllTimeMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20261127070034';

    public function testUpRenamesAllTimeToAllAndDownRestoresIt(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $this->insertView($connection, 'current-all-time', ['query' => ['period' => ['type' => 'all_time', 'year' => null]]]);
        $this->insertView($connection, 'legacy-flat-all-time', ['scopeGroup' => 'public', 'period' => 'all_time']);
        $this->insertView($connection, 'current-last-12-months', ['query' => ['period' => ['type' => 'last_12_months', 'year' => null]]]);
        $this->insertView($connection, 'current-year', ['query' => ['period' => ['type' => 'year', 'year' => 2025]]]);

        $this->migrate($connection, up: true);

        self::assertSame('all', $this->config($connection, 'current-all-time')['query']['period']['type']);
        self::assertNull($this->config($connection, 'current-all-time')['query']['period']['year']);
        self::assertSame('all', $this->config($connection, 'legacy-flat-all-time')['period']);
        self::assertSame('public', $this->config($connection, 'legacy-flat-all-time')['scopeGroup']);
        self::assertSame('last_12_months', $this->config($connection, 'current-last-12-months')['query']['period']['type']);
        self::assertSame('year', $this->config($connection, 'current-year')['query']['period']['type']);
        self::assertSame(2025, $this->config($connection, 'current-year')['query']['period']['year']);

        $this->migrate($connection, up: false);

        self::assertSame('all_time', $this->config($connection, 'current-all-time')['query']['period']['type']);
        self::assertSame('all_time', $this->config($connection, 'legacy-flat-all-time')['period']);
        self::assertSame('last_12_months', $this->config($connection, 'current-last-12-months')['query']['period']['type']);
    }

    private function migrate(Connection $connection, bool $up): void
    {
        $migration = $this->loadMigration();
        if ($up) {
            $migration->up(new Schema());
        } else {
            $migration->down(new Schema());
        }

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    private function loadMigration(): AbstractMigration
    {
        $dependencyFactory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $dependencyFactory);

        // Loads the migration files; the repository itself keeps one shared instance per version.
        self::assertTrue($dependencyFactory->getMigrationRepository()->hasMigration(self::MIGRATION));

        // A fresh instance per direction: planned SQL accumulates on the migration object.
        return $dependencyFactory->getMigrationFactory()->createVersion(self::MIGRATION);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function insertView(Connection $connection, string $slug, array $config): void
    {
        $connection->insert('saved_explorer_view', [
            'slug' => $slug,
            'title' => $slug,
            'category' => 'allocations',
            'config_json' => json_encode($config, \JSON_THROW_ON_ERROR),
            'is_system' => 'false',
            'visibility' => 'private',
            'created_at' => '2026-11-11 12:00:00',
            'updated_at' => '2026-11-11 12:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Connection $connection, string $slug): array
    {
        $json = $connection->fetchOne('SELECT config_json FROM saved_explorer_view WHERE slug = ?', [$slug]);
        self::assertIsString($json);

        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
