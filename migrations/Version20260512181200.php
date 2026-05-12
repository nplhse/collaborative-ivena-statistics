<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512181200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional case_id_hash and notes on allocation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allocation ADD case_id_hash BYTEA DEFAULT NULL');
        $this->addSql('ALTER TABLE allocation ADD notes VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_allocation_case_id_hash ON allocation (case_id_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_allocation_case_id_hash');
        $this->addSql('ALTER TABLE allocation DROP case_id_hash');
        $this->addSql('ALTER TABLE allocation DROP notes');
    }
}
