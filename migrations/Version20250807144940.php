<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250807144940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE state (id SERIAL NOT NULL, created_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A393D2FBB03A8386 ON state (created_by_id)');
        $this->addSql('CREATE INDEX IDX_A393D2FB896DBBDE ON state (updated_by_id)');
        $this->addSql('COMMENT ON COLUMN state.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN state.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE state ADD CONSTRAINT FK_A393D2FBB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE state ADD CONSTRAINT FK_A393D2FB896DBBDE FOREIGN KEY (updated_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE state DROP CONSTRAINT FK_A393D2FBB03A8386');
        $this->addSql('ALTER TABLE state DROP CONSTRAINT FK_A393D2FB896DBBDE');
        $this->addSql('DROP TABLE state');
    }
}
