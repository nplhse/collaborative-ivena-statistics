<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250824125824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE import ADD file_checksum VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE import ADD rows_passed INT DEFAULT NULL');
        $this->addSql('ALTER TABLE import ADD rows_rejected INT DEFAULT NULL');
        $this->addSql('ALTER TABLE import ADD reject_file_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE import DROP file_checksum');
        $this->addSql('ALTER TABLE import DROP rows_passed');
        $this->addSql('ALTER TABLE import DROP rows_rejected');
        $this->addSql('ALTER TABLE import DROP reject_file_path');
    }
}
