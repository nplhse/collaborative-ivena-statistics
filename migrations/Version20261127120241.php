<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds two indexes that the production database already has, but no migration created (#1752): the lookup of
 * normalized indications by code and of unmatched raw indications by code. `IF NOT EXISTS` keeps the existing
 * indexes in production; every other installation gets them now. The mapping declares both (#1398).
 *
 * down() drops them, also where they existed before this migration.
 */
final class Version20261127120241 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the code indexes of indication_normalized and indication_raw that existed only in production.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_indication_normalized_code ON indication_normalized (code)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_indication_raw_unmatched_code ON indication_raw (code) WHERE (target_id IS NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_indication_raw_unmatched_code');
        $this->addSql('DROP INDEX IF EXISTS idx_indication_normalized_code');
    }
}
