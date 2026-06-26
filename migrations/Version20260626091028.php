<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626091028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indication raw review workflow fields (review status, four-eyes matching).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE indication_raw ADD review_status VARCHAR(32) NOT NULL DEFAULT 'unreviewed'");
        $this->addSql('ALTER TABLE indication_raw ADD review_comment TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE indication_raw ADD reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE indication_raw ADD reviewed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE indication_raw ADD first_matched_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE indication_raw ADD first_matched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE indication_raw ADD CONSTRAINT FK_indication_raw_reviewed_by FOREIGN KEY (reviewed_by_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE indication_raw ADD CONSTRAINT FK_indication_raw_first_matched_by FOREIGN KEY (first_matched_by_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_indication_raw_review_status ON indication_raw (review_status)');
        $this->addSql('CREATE INDEX idx_indication_raw_review_status_created_at ON indication_raw (review_status, created_at)');

        $this->addSql(<<<'SQL'
UPDATE indication_raw
SET review_status = 'matched',
    reviewed_at = COALESCE(updated_at, created_at),
    first_matched_by_id = updated_by_id
WHERE target_id IS NOT NULL
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE indication_raw DROP CONSTRAINT FK_indication_raw_first_matched_by');
        $this->addSql('ALTER TABLE indication_raw DROP CONSTRAINT FK_indication_raw_reviewed_by');
        $this->addSql('DROP INDEX idx_indication_raw_review_status_created_at');
        $this->addSql('DROP INDEX idx_indication_raw_review_status');
        $this->addSql('ALTER TABLE indication_raw DROP first_matched_at');
        $this->addSql('ALTER TABLE indication_raw DROP first_matched_by_id');
        $this->addSql('ALTER TABLE indication_raw DROP reviewed_by_id');
        $this->addSql('ALTER TABLE indication_raw DROP reviewed_at');
        $this->addSql('ALTER TABLE indication_raw DROP review_comment');
        $this->addSql('ALTER TABLE indication_raw DROP review_status');
    }
}
