<?php

declare(strict_types=1);

namespace App\LegacyMigration\Infrastructure\Doctrine;

use App\LegacyMigration\Domain\Model\LegacyMigrationStatus;
use Doctrine\DBAL\Connection;

final readonly class LegacyMigrationSchemaManager
{
    /** @var list<string> */
    public const array ALLOWLIST_TABLES = [
        'legacy_migration_user_mapping',
        'legacy_migration_hospital_mapping',
        'legacy_migration_import_mapping',
        'legacy_migration_allocation_mapping',
        'legacy_migration_log',
        'legacy_migration_run',
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $defaultConnection,
    ) {
    }

    public function install(): void
    {
        $sql = [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS legacy_migration_user_mapping (
    id SERIAL PRIMARY KEY,
    legacy_user_id INT NOT NULL,
    new_user_id INT NOT NULL,
    legacy_email VARCHAR(255) DEFAULT NULL,
    migrated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_legacy_migration_user_mapping_legacy_user_id ON legacy_migration_user_mapping (legacy_user_id)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS legacy_migration_hospital_mapping (
    id SERIAL PRIMARY KEY,
    legacy_hospital_id INT NOT NULL,
    new_hospital_id INT NOT NULL,
    legacy_name VARCHAR(255) NOT NULL,
    matched_name VARCHAR(255) NOT NULL,
    match_score DOUBLE PRECISION NOT NULL,
    migrated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_legacy_migration_hospital_mapping_legacy_hospital_id ON legacy_migration_hospital_mapping (legacy_hospital_id)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS legacy_migration_import_mapping (
    id SERIAL PRIMARY KEY,
    legacy_import_id INT NOT NULL,
    new_import_id INT DEFAULT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    last_allocation_id INT DEFAULT NULL,
    migrated_count INT NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_legacy_migration_import_mapping_legacy_import_id ON legacy_migration_import_mapping (legacy_import_id)',
            'CREATE INDEX IF NOT EXISTS idx_legacy_migration_import_mapping_status ON legacy_migration_import_mapping (status)',
            'CREATE INDEX IF NOT EXISTS idx_legacy_migration_import_mapping_last_allocation_id ON legacy_migration_import_mapping (last_allocation_id)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS legacy_migration_allocation_mapping (
    id SERIAL PRIMARY KEY,
    legacy_allocation_id INT NOT NULL,
    new_allocation_id INT NOT NULL,
    legacy_import_id INT NOT NULL,
    migrated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_legacy_migration_allocation_mapping_legacy_allocation_id ON legacy_migration_allocation_mapping (legacy_allocation_id)',
            'CREATE INDEX IF NOT EXISTS idx_legacy_migration_allocation_mapping_legacy_import_id ON legacy_migration_allocation_mapping (legacy_import_id)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS legacy_migration_log (
    id SERIAL PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    legacy_id INT DEFAULT NULL,
    level VARCHAR(16) NOT NULL,
    message TEXT NOT NULL,
    context JSON DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE INDEX IF NOT EXISTS idx_legacy_migration_log_scope ON legacy_migration_log (scope)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS legacy_migration_run (
    id SERIAL PRIMARY KEY,
    status VARCHAR(16) NOT NULL,
    started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE INDEX IF NOT EXISTS idx_legacy_migration_run_status ON legacy_migration_run (status)',
        ];

        foreach ($sql as $statement) {
            $this->defaultConnection->executeStatement($statement);
        }
    }

    public function uninstall(bool $force = false): void
    {
        if (!$force) {
            $runningOrFailed = (int) $this->defaultConnection->fetchOne(
                "SELECT COUNT(*) FROM legacy_migration_import_mapping WHERE status IN ('running', 'failed')"
            );
            if ($runningOrFailed > 0) {
                throw new \RuntimeException('Cannot uninstall while imports are running or failed. Use --force to override.');
            }
        }

        foreach (self::ALLOWLIST_TABLES as $table) {
            $this->defaultConnection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }

    public function isInstalled(): bool
    {
        return $this->defaultConnection->createSchemaManager()->tablesExist(self::ALLOWLIST_TABLES);
    }

    public function getStatus(): LegacyMigrationStatus
    {
        if (!$this->isInstalled()) {
            return new LegacyMigrationStatus(false, 0, 0, 0, 0, 0, [], null);
        }

        $statusCounts = $this->defaultConnection->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM legacy_migration_import_mapping GROUP BY status'
        );

        $lastError = $this->defaultConnection->fetchOne(
            "SELECT message FROM legacy_migration_log WHERE level = 'error' ORDER BY id DESC LIMIT 1"
        );
        $lastErrorMessage = \is_string($lastError) ? $lastError : null;

        return new LegacyMigrationStatus(
            true,
            (int) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM legacy_migration_user_mapping'),
            (int) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM legacy_migration_hospital_mapping'),
            (int) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM legacy_migration_import_mapping'),
            (int) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM legacy_migration_allocation_mapping'),
            (int) $this->defaultConnection->fetchOne('SELECT COALESCE(SUM(migrated_count), 0) FROM legacy_migration_import_mapping'),
            array_map(static fn (mixed $v): int => (int) $v, $statusCounts),
            $lastErrorMessage,
        );
    }

    public function assertInstalled(): void
    {
        if (!$this->isInstalled()) {
            throw new \RuntimeException('Legacy migration tables are not installed. Run php bin/console app:legacy-migration:install first.');
        }
    }
}
