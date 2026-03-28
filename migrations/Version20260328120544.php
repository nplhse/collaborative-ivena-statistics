<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328120544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hospital_tier_code and hospital_location_code to allocation_stats_projection for distribution analytics.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allocation_stats_projection ADD hospital_tier_code SMALLINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_asp_hospital_tier_code ON allocation_stats_projection (hospital_tier_code)');
        $this->addSql('ALTER TABLE allocation_stats_projection ADD hospital_location_code SMALLINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_asp_hospital_location_code ON allocation_stats_projection (hospital_location_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_asp_hospital_location_code');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP hospital_location_code');
        $this->addSql('DROP INDEX idx_asp_hospital_tier_code');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP hospital_tier_code');
    }
}
