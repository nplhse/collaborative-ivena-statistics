<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507073623 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_work_accident to allocation and allocation_stats_projection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allocation ADD is_work_accident BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE allocation_stats_projection ADD is_work_accident BOOLEAN DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_asp_is_work_accident ON allocation_stats_projection (is_work_accident)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_asp_is_work_accident');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP is_work_accident');
        $this->addSql('ALTER TABLE allocation DROP is_work_accident');
    }
}
