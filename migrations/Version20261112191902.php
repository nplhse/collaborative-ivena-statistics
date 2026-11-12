<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Encounter upload file retention (ADR 025, issue #1049): when the uploaded file of an encounter
 * import was deleted. Rows deleted before this migration keep a null date.
 */
final class Version20261112191902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encounter_import.source_file_deleted_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_import ADD source_file_deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_import DROP source_file_deleted_at');
    }
}
