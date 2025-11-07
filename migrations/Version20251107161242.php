<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251107161242 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds total column to agg_allocations_top_categories and backfills existing rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            ALTER TABLE agg_allocations_top_categories
            ADD COLUMN total INT NOT NULL DEFAULT 0
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('
            ALTER TABLE agg_allocations_top_categories
            DROP COLUMN total
        ');
    }
}
