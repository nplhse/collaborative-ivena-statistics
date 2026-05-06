<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_shock and is_pregnant to allocation_stats_projection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allocation_stats_projection ADD is_shock BOOLEAN DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_asp_is_shock ON allocation_stats_projection (is_shock)');
        $this->addSql('ALTER TABLE allocation_stats_projection ADD is_pregnant BOOLEAN DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_asp_is_pregnant ON allocation_stats_projection (is_pregnant)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_asp_is_pregnant');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP is_pregnant');
        $this->addSql('DROP INDEX idx_asp_is_shock');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP is_shock');
    }
}
