<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The one-time legacy district takeover of v0.3 is removed (#1519); its completion marker goes with it. v0.4 requires
 * v0.3 with the completed takeover first. Down recreates the empty table; the marker row is not restored.
 */
final class Version20261127073807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the completion marker of the legacy district takeover (#1519)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS district_legacy_takeover');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE district_legacy_takeover (id INT NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, transfer_file_hash VARCHAR(64) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE district_legacy_takeover ADD CONSTRAINT chk_district_legacy_takeover_single_row CHECK (id = 1)');
    }
}
