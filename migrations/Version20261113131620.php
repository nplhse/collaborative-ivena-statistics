<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Match validation (issue #1057): a pair judged "wrong" because another visit fits keeps that visit, so the
 * report can tell "wrong, other visit" from "wrong, no partner". Only "wrong" carries a replacement.
 */
final class Version20261113131620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add replacement_encounter_id to encounter_validation_item.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_validation_item ADD replacement_encounter_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_validation_item ADD CONSTRAINT FK_7DE65A028217D858 FOREIGN KEY (replacement_encounter_id) REFERENCES encounter (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7DE65A028217D858 ON encounter_validation_item (replacement_encounter_id)');
        $this->addSql('ALTER TABLE encounter_validation_item ADD CONSTRAINT chk_encounter_validation_item_replacement CHECK (replacement_encounter_id IS NULL OR verdict = \'wrong\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_validation_item DROP CONSTRAINT chk_encounter_validation_item_replacement');
        $this->addSql('ALTER TABLE encounter_validation_item DROP CONSTRAINT FK_7DE65A028217D858');
        $this->addSql('DROP INDEX IDX_7DE65A028217D858');
        $this->addSql('ALTER TABLE encounter_validation_item DROP replacement_encounter_id');
    }
}
