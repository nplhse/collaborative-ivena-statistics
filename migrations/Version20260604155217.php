<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604155217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add day_time_bucket_code and shift_bucket_code to allocation_stats_projection with indication dashboard indexes.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allocation_stats_projection ADD day_time_bucket_code SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE allocation_stats_projection ADD shift_bucket_code SMALLINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
UPDATE allocation_stats_projection SET
    day_time_bucket_code = CASE
        WHEN EXTRACT(HOUR FROM created_at)::INT < 6 THEN 1
        WHEN EXTRACT(HOUR FROM created_at)::INT < 12 THEN 2
        WHEN EXTRACT(HOUR FROM created_at)::INT < 18 THEN 3
        ELSE 4
    END,
    shift_bucket_code = CASE
        WHEN EXTRACT(HOUR FROM created_at)::INT >= 22 OR EXTRACT(HOUR FROM created_at)::INT < 6 THEN 1
        WHEN EXTRACT(HOUR FROM created_at)::INT < 14 THEN 2
        ELSE 3
    END
SQL);

        $this->addSql('ALTER TABLE allocation_stats_projection ALTER COLUMN day_time_bucket_code SET NOT NULL');
        $this->addSql('ALTER TABLE allocation_stats_projection ALTER COLUMN shift_bucket_code SET NOT NULL');

        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_day_time_bucket ON allocation_stats_projection (day_time_bucket_code)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_shift_bucket ON allocation_stats_projection (shift_bucket_code)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_indication_created ON allocation_stats_projection (indication_normalized_id, created_at)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_indication_hospital_created ON allocation_stats_projection (indication_normalized_id, hospital_id, created_at)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_indication_weekday_daytime ON allocation_stats_projection (indication_normalized_id, created_weekday, day_time_bucket_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_indication_weekday_daytime');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_indication_hospital_created');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_indication_created');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_shift_bucket');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_day_time_bucket');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP shift_bucket_code');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP day_time_bucket_code');
    }
}
