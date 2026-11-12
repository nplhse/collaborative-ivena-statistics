<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Feedback classification for the spam quarantine (issue #964). Existing entries stay normal; moving old
 * spam into quarantine is a separate, reviewed step.
 */
final class Version20261112235620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feedback classification, quarantine reasons, score, reviewer and summary columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE feedback ADD classification VARCHAR(255) DEFAULT 'normal' NOT NULL");
        $this->addSql('ALTER TABLE feedback ADD classification_reasons JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback ADD spam_score SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback ADD classified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback ADD quarantine_reported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback ADD classified_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback ADD CONSTRAINT FK_D2294458CF8EF351 FOREIGN KEY (classified_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D2294458CF8EF351 ON feedback (classified_by_id)');
        $this->addSql('CREATE INDEX idx_feedback_classification_created ON feedback (classification, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_feedback_classification_created');
        $this->addSql('DROP INDEX IDX_D2294458CF8EF351');
        $this->addSql('ALTER TABLE feedback DROP CONSTRAINT FK_D2294458CF8EF351');
        $this->addSql('ALTER TABLE feedback DROP classified_by_id');
        $this->addSql('ALTER TABLE feedback DROP quarantine_reported_at');
        $this->addSql('ALTER TABLE feedback DROP classified_at');
        $this->addSql('ALTER TABLE feedback DROP spam_score');
        $this->addSql('ALTER TABLE feedback DROP classification_reasons');
        $this->addSql('ALTER TABLE feedback DROP classification');
    }
}
