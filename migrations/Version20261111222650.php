<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records the development-only target hospital override on imports (issue #878).
 * Existing imports were never overridden.
 */
final class Version20261111222650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import.hospital_override and import.hospital_override_source_label.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import ADD hospital_override BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE import ADD hospital_override_source_label VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import DROP hospital_override_source_label');
        $this->addSql('ALTER TABLE import DROP hospital_override');
    }
}
