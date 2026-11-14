<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Learning job of the plausibility table (ADR 033, #1155): a learned snapshot records the period of its eligible pairs
 * (IVENA arrival days), their number over all hospitals, the learning parameters and the report with replay figures
 * per hospital. Seed snapshots leave the columns empty.
 */
final class Version20261114092903 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record period, eligible pairs, parameters and report of learned plausibility snapshots';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot ADD learned_from DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot ADD learned_to DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot ADD eligible_pairs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot ADD learning_parameters JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot ADD report JSONB DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot DROP learned_from');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot DROP learned_to');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot DROP eligible_pairs');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot DROP learning_parameters');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot DROP report');
    }
}
