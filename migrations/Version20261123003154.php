<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Completion marker of the one-time takeover of the legacy district geography (#1406, ADR 041). At most one row.
 */
final class Version20261123003154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the completion marker of the legacy district takeover.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE district_legacy_takeover (id INT NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, transfer_file_hash VARCHAR(64) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE district_legacy_takeover ADD CONSTRAINT chk_district_legacy_takeover_single_row CHECK (id = 1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE district_legacy_takeover');
    }
}
