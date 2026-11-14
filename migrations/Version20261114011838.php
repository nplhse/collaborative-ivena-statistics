<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Matcher 1.2.0 (ADR 030 amendment, ADR 033, #1154): every pair records the plausibility snapshot and cell level of its
 * pass-2 bonus and whether the bonus was decisive. Existing pairs were scored without a bonus: no snapshot, no level,
 * not decisive. The snapshot reference has no cascade: active and retired snapshots are never deleted while pairs
 * reference them.
 */
final class Version20261114011838 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the plausibility snapshot, level and decisive flag per encounter match';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_match ADD plausibility_snapshot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_match ADD plausibility_level VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter_match ADD plausibility_decisive BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE encounter_match ADD CONSTRAINT FK_ENCOUNTER_MATCH_PLAUSIBILITY_SNAPSHOT FOREIGN KEY (plausibility_snapshot_id) REFERENCES encounter_plausibility_snapshot (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_479AD883EC4904C6 ON encounter_match (plausibility_snapshot_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter_match DROP CONSTRAINT FK_ENCOUNTER_MATCH_PLAUSIBILITY_SNAPSHOT');
        $this->addSql('DROP INDEX IDX_479AD883EC4904C6');
        $this->addSql('ALTER TABLE encounter_match DROP plausibility_snapshot_id');
        $this->addSql('ALTER TABLE encounter_match DROP plausibility_level');
        $this->addSql('ALTER TABLE encounter_match DROP plausibility_decisive');
    }
}
