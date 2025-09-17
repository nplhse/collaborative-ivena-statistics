<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250917054935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE indication_raw ADD normalized_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE indication_raw ADD CONSTRAINT FK_E928F55227AD842E FOREIGN KEY (normalized_id) REFERENCES indication_normalized (id)');
        $this->addSql('CREATE INDEX IDX_E928F55227AD842E ON indication_raw (normalized_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE indication_raw DROP CONSTRAINT FK_E928F55227AD842E');
        $this->addSql('DROP INDEX IDX_E928F55227AD842E');
        $this->addSql('ALTER TABLE indication_raw DROP normalized_id');
    }
}
