<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519125523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes on allocation_stats_projection for overview analytics.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_location_tier_hospital ON allocation_stats_projection (hospital_location_code, hospital_tier_code, hospital_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_state_hospital ON allocation_stats_projection (state_id, hospital_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_dispatch_area_hospital ON allocation_stats_projection (dispatch_area_id, hospital_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_hospital_created ON allocation_stats_projection (hospital_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_hospital_created');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_dispatch_area_hospital');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_state_hospital');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_location_tier_hospital');
    }
}
