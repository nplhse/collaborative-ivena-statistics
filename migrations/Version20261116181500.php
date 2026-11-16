<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Effective assignment of an encounter to an IVENA allocation on the encounter itself (#1300, ADR 037):
 * `encounter.allocation_id`, unique, `ON DELETE SET NULL` so that the encounter and its clinical records stay
 * when the allocation goes. Filled from the effective rows of `encounter_match` (auto-accepted or confirmed),
 * which stay as the ED matching history. The down migration drops the columns; the effective
 * `encounter_match` rows still hold every assignment made by the ED matching.
 */
final class Version20261116181500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the effective IVENA assignment encounter.allocation_id (#1300)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter ADD allocation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter ADD allocation_assigned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter ADD allocation_assigned_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT FK_ENCOUNTER_ALLOCATION FOREIGN KEY (allocation_id) REFERENCES allocation (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT FK_ENCOUNTER_ALLOCATION_ASSIGNED_BY FOREIGN KEY (allocation_assigned_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_allocation ON encounter (allocation_id)');
        $this->addSql('CREATE INDEX IDX_ENCOUNTER_ALLOCATION_ASSIGNED_BY ON encounter (allocation_assigned_by_id)');
        $this->addSql(<<<'SQL'
UPDATE encounter e
SET allocation_id = m.allocation_id,
    allocation_assigned_at = COALESCE(m.decided_at, m.created_at),
    allocation_assigned_by_id = m.decided_by_id
FROM encounter_match m
WHERE m.encounter_id = e.id AND m.status IN ('auto_accepted', 'confirmed')
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT FK_ENCOUNTER_ALLOCATION');
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT FK_ENCOUNTER_ALLOCATION_ASSIGNED_BY');
        $this->addSql('DROP INDEX uniq_encounter_allocation');
        $this->addSql('DROP INDEX IDX_ENCOUNTER_ALLOCATION_ASSIGNED_BY');
        $this->addSql('ALTER TABLE encounter DROP allocation_id');
        $this->addSql('ALTER TABLE encounter DROP allocation_assigned_at');
        $this->addSql('ALTER TABLE encounter DROP allocation_assigned_by_id');
    }
}
