<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250808141250 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dispatch_area (id SERIAL NOT NULL, state_id INT NOT NULL, created_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_94247A155D83CC1 ON dispatch_area (state_id)');
        $this->addSql('CREATE INDEX IDX_94247A15B03A8386 ON dispatch_area (created_by_id)');
        $this->addSql('CREATE INDEX IDX_94247A15896DBBDE ON dispatch_area (updated_by_id)');
        $this->addSql('COMMENT ON COLUMN dispatch_area.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN dispatch_area.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE dispatch_area ADD CONSTRAINT FK_94247A155D83CC1 FOREIGN KEY (state_id) REFERENCES state (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE dispatch_area ADD CONSTRAINT FK_94247A15B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE dispatch_area ADD CONSTRAINT FK_94247A15896DBBDE FOREIGN KEY (updated_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE dispatch_area DROP CONSTRAINT FK_94247A155D83CC1');
        $this->addSql('ALTER TABLE dispatch_area DROP CONSTRAINT FK_94247A15B03A8386');
        $this->addSql('ALTER TABLE dispatch_area DROP CONSTRAINT FK_94247A15896DBBDE');
        $this->addSql('DROP TABLE dispatch_area');
    }
}
