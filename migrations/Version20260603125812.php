<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260603125812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove legacy migration tracking tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS legacy_migration_allocation_mapping');
        $this->addSql('DROP TABLE IF EXISTS legacy_migration_import_mapping');
        $this->addSql('DROP TABLE IF EXISTS legacy_migration_user_mapping');
        $this->addSql('DROP TABLE IF EXISTS legacy_migration_hospital_mapping');
        $this->addSql('DROP TABLE IF EXISTS legacy_migration_log');
        $this->addSql('DROP TABLE IF EXISTS legacy_migration_run');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Legacy migration tracking tables cannot be restored automatically.');
    }
}
