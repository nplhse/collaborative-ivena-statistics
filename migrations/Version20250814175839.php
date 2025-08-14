<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250814175839 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE allocation ADD age INT NOT NULL');
        $this->addSql('ALTER TABLE allocation ADD transport_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE allocation ALTER gender TYPE VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE allocation DROP age');
        $this->addSql('ALTER TABLE allocation DROP transport_type');
        $this->addSql('ALTER TABLE allocation ALTER gender TYPE VARCHAR(1)');
    }
}
