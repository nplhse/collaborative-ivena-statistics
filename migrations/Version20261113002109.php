<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fingerprint of the normalised feedback message for spotting repeated spam (issue #964). Existing rows
 * stay without one; the reclassification command fills them in.
 */
final class Version20261113002109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feedback.message_fingerprint with an index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback ADD message_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_feedback_message_fingerprint ON feedback (message_fingerprint)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_feedback_message_fingerprint');
        $this->addSql('ALTER TABLE feedback DROP message_fingerprint');
    }
}
