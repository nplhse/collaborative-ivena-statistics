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
 * #1040: saved explorer views with the age-group filter `0_18` or `19_29` are rewritten to `0_17` / `18_29`.
 * Other filters, other age groups and the filter order stay as they are.
 */
#[ResetDatabase]
final class SavedExplorerViewAgeGroupMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\\Version20261113144614';

    public function testUpRewritesOldAgeGroupsAndDownRestoresThem(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $this->insertView($connection, 'minors', [
            ['dimensionKey' => 'gender', 'operator' => 'equals', 'value' => 2],
            ['dimensionKey' => 'age_group', 'operator' => 'equals', 'value' => '0_18'],
        ]);
        $this->insertView($connection, 'young-adults', [
            ['dimensionKey' => 'age_group', 'operator' => 'equals', 'value' => '19_29'],
        ]);
        $this->insertView($connection, 'thirties', [
            ['dimensionKey' => 'age_group', 'operator' => 'equals', 'value' => '30_39'],
        ]);
        $this->insertView($connection, 'adults', [
            ['dimensionKey' => 'age_group', 'operator' => 'equals', 'value' => 'adults'],
        ]);
        $this->insertView($connection, 'no-filters', []);

        $this->migrate($connection, up: true);

        // jsonb reorders object keys, so compare the filters without key order (the list order still counts).
        self::assertEquals([
            ['dimensionKey' => 'gender', 'operator' => 'equals', 'value' => 2],
            ['dimensionKey' => 'age_group', 'operator' => 'equals', 'value' => '0_17'],
        ], $this->filters($connection, 'minors'));
        self::assertSame('18_29', $this->filters($connection, 'young-adults')[0]['value']);
        self::assertSame('30_39', $this->filters($connection, 'thirties')[0]['value']);
        self::assertSame('adults', $this->filters($connection, 'adults')[0]['value']);
        self::assertSame([], $this->filters($connection, 'no-filters'));
        self::assertSame('allocations', $this->config($connection, 'minors')['dataSource']);

        $this->migrate($connection, up: false);

        self::assertSame('0_18', $this->filters($connection, 'minors')[1]['value']);
        self::assertSame(2, $this->filters($connection, 'minors')[0]['value']);
        self::assertSame('19_29', $this->filters($connection, 'young-adults')[0]['value']);
        self::assertSame('30_39', $this->filters($connection, 'thirties')[0]['value']);
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

        self::assertTrue($dependencyFactory->getMigrationRepository()->hasMigration(self::MIGRATION));

        // A fresh instance per direction: planned SQL accumulates on the migration object.
        return $dependencyFactory->getMigrationFactory()->createVersion(self::MIGRATION);
    }

    /**
     * @param list<array<string, mixed>> $filters
     */
    private function insertView(Connection $connection, string $slug, array $filters): void
    {
        $config = [
            'schemaVersion' => 4,
            'dataSource' => 'allocations',
            'query' => ['rows' => ['dimension' => 'month'], 'filters' => $filters],
            'presentation' => ['chartType' => 'bar'],
        ];

        $connection->insert('saved_explorer_view', [
            'slug' => $slug,
            'title' => $slug,
            'category' => 'allocations',
            'config_json' => json_encode($config, \JSON_THROW_ON_ERROR),
            'is_system' => 'false',
            'visibility' => 'private',
            'created_at' => '2026-11-13 12:00:00',
            'updated_at' => '2026-11-13 12:00:00',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filters(Connection $connection, string $slug): array
    {
        $query = $this->config($connection, $slug)['query'];
        self::assertIsArray($query);
        self::assertIsArray($query['filters']);

        /** @var list<array<string, mixed>> $filters */
        $filters = $query['filters'];

        return $filters;
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
