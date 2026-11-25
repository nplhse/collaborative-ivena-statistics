<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The encounter upload confirmation also covers the agreement with the hospital's data protection and keeps the
 * terms version in force (#1563). Existing uploads did not confirm it: false, no version.
 */
final class Version20261125063048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the data protection confirmation and the terms version on encounter imports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_import ADD data_protection_confirmed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE encounter_import ADD terms_version VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_import DROP data_protection_confirmed');
        $this->addSql('ALTER TABLE encounter_import DROP terms_version');
    }
}
