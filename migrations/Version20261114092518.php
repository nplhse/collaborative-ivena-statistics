<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Match validation by plausibility snapshot (#1156): a sampled pair keeps the snapshot of its pass-2 bonus and whether
 * the bonus was decisive, as the pair row has them when the sample is drawn. Items drawn before get no snapshot and
 * `plausibility_decisive` NULL ("not recorded"); the report shows them as a group of their own instead of guessing.
 * Unlinked cases keep NULL. The snapshot reference has no cascade, like the one of `encounter_match`.
 */
final class Version20261114092518 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the plausibility snapshot and decisive flag per match validation item';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_validation_item ADD plausibility_snapshot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_validation_item ADD plausibility_decisive BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_validation_item ADD CONSTRAINT FK_ENCOUNTER_VALIDATION_ITEM_PLAUSIBILITY_SNAPSHOT FOREIGN KEY (plausibility_snapshot_id) REFERENCES encounter_plausibility_snapshot (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7DE65A02EC4904C6 ON encounter_validation_item (plausibility_snapshot_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_validation_item DROP CONSTRAINT FK_ENCOUNTER_VALIDATION_ITEM_PLAUSIBILITY_SNAPSHOT');
        $this->addSql('DROP INDEX IDX_7DE65A02EC4904C6');
        $this->addSql('ALTER TABLE encounter_validation_item DROP plausibility_snapshot_id');
        $this->addSql('ALTER TABLE encounter_validation_item DROP plausibility_decisive');
    }
}
