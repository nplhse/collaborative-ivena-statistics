<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 4 (ADR 013): drop transitional legacy content columns from page.
 * Content lives only on page_translation after this migration.
 */
final class Version20260812160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy page title/slug/path/content/status columns after PageTranslation migration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_page_path');
        $this->addSql('DROP INDEX IF EXISTS uniq_page_parent_slug');
        $this->addSql('ALTER TABLE page DROP COLUMN title');
        $this->addSql('ALTER TABLE page DROP COLUMN slug');
        $this->addSql('ALTER TABLE page DROP COLUMN path');
        $this->addSql('ALTER TABLE page DROP COLUMN content');
        $this->addSql('ALTER TABLE page DROP COLUMN status');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE page ADD title VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE page ADD slug VARCHAR(180) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE page ADD path VARCHAR(500) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE page ADD status VARCHAR(32) DEFAULT 'draft' NOT NULL");
        $this->addSql("ALTER TABLE page ADD content JSON DEFAULT '[]' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_page_path ON page (path)');
        $this->addSql('CREATE UNIQUE INDEX uniq_page_parent_slug ON page (parent_id, slug)');
    }
}
