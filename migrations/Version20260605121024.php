<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260605121024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indication dashboard slice indexes on allocation_stats_projection.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_hospital_indication ON allocation_stats_projection (hospital_id, indication_normalized_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_asp_indication_weekday_shift ON allocation_stats_projection (indication_normalized_id, created_weekday, shift_bucket_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_indication_weekday_shift');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_asp_hospital_indication');
    }
}
